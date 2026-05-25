-- System audit repair migration (2026-05-25)
-- Fixes: E2 (no unique on recipient_email), E4 (zone TEXT vs TEXT[]), E9 (RPC missing zone/interests)

-- E2: Add UNIQUE constraint on sent_outreach_emails.recipient_email
-- Without this the dedup check in ha_supabase_check_and_record_sent_email never receives a 409.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sent_outreach_emails_recipient_email_key'
          AND conrelid = 'public.sent_outreach_emails'::regclass
    ) THEN
        ALTER TABLE public.sent_outreach_emails
            ADD CONSTRAINT sent_outreach_emails_recipient_email_key UNIQUE (recipient_email);
    END IF;
END;
$$;

-- E4: Change customers.zone from TEXT (scalar) to TEXT[] (array)
-- The PHP layer and deal_matcher.py both treat zone as an array; the original migration
-- defined it as TEXT, creating a type mismatch that breaks deal matching.
-- Guard: only runs if the column is still plain text, handles the case where the
-- live DB was already manually patched.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name  = 'customers'
          AND column_name = 'zone'
          AND data_type   = 'text'
          AND udt_name    = 'text'
    ) THEN
        ALTER TABLE public.customers
            ALTER COLUMN zone TYPE TEXT[]
            USING CASE WHEN zone IS NULL THEN NULL ELSE ARRAY[zone]::text[] END;
    END IF;
END;
$$;

-- E9: Update create_customer_and_loyalty_card to accept and store zone and interests.
-- Parameters are optional (DEFAULT NULL) to preserve backward compatibility with
-- existing PHP callers that do not supply them.
CREATE OR REPLACE FUNCTION public.create_customer_and_loyalty_card(
    p_merchant_id  uuid,
    p_full_name    text,
    p_phone_number text,
    p_zone         TEXT[]  DEFAULT NULL,
    p_interests    TEXT[]  DEFAULT NULL
)
RETURNS TABLE(
    status         text,
    message        text,
    loyalty_card_id uuid
)
LANGUAGE plpgsql
AS $function$
DECLARE
    v_customer_id      uuid;
    v_loyalty_program_id uuid;
    v_loyalty_card_id  uuid;
BEGIN
    -- Upsert customer; on phone_number conflict update zone/interests so a
    -- loyalty signup that supplies them patches an existing sparse record.
    WITH ins AS (
        INSERT INTO public.customers (full_name, phone_number, zone, interests)
        VALUES (p_full_name, p_phone_number, p_zone, p_interests)
        ON CONFLICT (phone_number) DO UPDATE
            SET zone      = COALESCE(EXCLUDED.zone,      public.customers.zone),
                interests = COALESCE(EXCLUDED.interests, public.customers.interests),
                full_name = COALESCE(EXCLUDED.full_name, public.customers.full_name)
        RETURNING id
    )
    SELECT id INTO v_customer_id FROM ins
    UNION ALL
    SELECT id FROM public.customers WHERE phone_number = p_phone_number AND NOT EXISTS (SELECT 1 FROM ins)
    LIMIT 1;

    -- Return existing active card for this merchant if one already exists.
    SELECT id INTO v_loyalty_card_id
    FROM public.loyalty_cards
    WHERE customer_id  = v_customer_id
      AND merchant_id  = p_merchant_id
      AND loyalty_cards.status = 'active'
    LIMIT 1;

    IF v_loyalty_card_id IS NOT NULL THEN
        RETURN QUERY SELECT 'success'::text, 'Customer already has an active card.'::text, v_loyalty_card_id;
        RETURN;
    END IF;

    -- Locate the merchant's loyalty program.
    SELECT id INTO v_loyalty_program_id
    FROM public.loyalty_programs
    WHERE merchant_id = p_merchant_id
    LIMIT 1;

    IF v_loyalty_program_id IS NULL THEN
        RETURN QUERY SELECT 'error'::text, 'This merchant does not have a loyalty program.'::text, NULL::uuid;
        RETURN;
    END IF;

    -- Create the loyalty card.
    INSERT INTO public.loyalty_cards (customer_id, merchant_id, loyalty_program_id)
    VALUES (v_customer_id, p_merchant_id, v_loyalty_program_id)
    RETURNING id INTO v_loyalty_card_id;

    RETURN QUERY SELECT 'success'::text, 'New loyalty card created.'::text, v_loyalty_card_id;

EXCEPTION
    WHEN OTHERS THEN
        RETURN QUERY SELECT 'error'::text, 'An unexpected error occurred: ' || SQLERRM, NULL::uuid;
END;
$function$;
