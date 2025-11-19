-- This migration creates a function to handle a new customer signing up
-- for a specific merchant's loyalty program.

CREATE OR REPLACE FUNCTION public.create_customer_and_loyalty_card(
    p_merchant_id uuid,
    p_full_name text,
    p_phone_number text
)
RETURNS TABLE(
    status text,
    message text,
    loyalty_card_id uuid
)
LANGUAGE plpgsql
AS $function$
DECLARE
    v_customer_id uuid;
    v_loyalty_program_id uuid;
    v_loyalty_card_id uuid;
BEGIN
    -- Upsert the customer: Insert or do nothing if the phone number already exists.
    -- We get the ID back in either case.
    WITH ins AS (
        INSERT INTO public.customers (full_name, phone_number)
        VALUES (p_full_name, p_phone_number)
        ON CONFLICT (phone_number) DO NOTHING
        RETURNING id
    )
    SELECT id INTO v_customer_id FROM ins
    UNION ALL
    SELECT id FROM public.customers WHERE phone_number = p_phone_number AND NOT EXISTS (SELECT 1 FROM ins)
    LIMIT 1;

    -- Check if the customer already has an active card for this merchant
    SELECT id INTO v_loyalty_card_id
    FROM public.loyalty_cards
    WHERE customer_id = v_customer_id
      AND merchant_id = p_merchant_id
      AND loyalty_cards.status = 'active' -- Explicitly reference the table's column
    LIMIT 1;

    -- If they already have an active card, just return that one.
    IF v_loyalty_card_id IS NOT NULL THEN
        RETURN QUERY SELECT 'success'::text, 'Customer already has an active card.'::text, v_loyalty_card_id;
        RETURN;
    END IF;

    -- Find the merchant's loyalty program
    SELECT id INTO v_loyalty_program_id
    FROM public.loyalty_programs
    WHERE merchant_id = p_merchant_id
    LIMIT 1;

    IF v_loyalty_program_id IS NULL THEN
        RETURN QUERY SELECT 'error'::text, 'This merchant does not have a loyalty program.'::text, NULL::uuid;
        RETURN;
    END IF;

    -- Create a new loyalty card for the customer
    INSERT INTO public.loyalty_cards (customer_id, merchant_id, loyalty_program_id)
    VALUES (v_customer_id, p_merchant_id, v_loyalty_program_id)
    RETURNING id INTO v_loyalty_card_id;

    RETURN QUERY SELECT 'success'::text, 'New loyalty card created.'::text, v_loyalty_card_id;

EXCEPTION
    WHEN OTHERS THEN
        RETURN QUERY SELECT 'error'::text, 'An unexpected error occurred: ' || SQLERRM, NULL::uuid;
END;
$function$;
