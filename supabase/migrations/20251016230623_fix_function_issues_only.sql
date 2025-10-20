set check_function_bodies = off;

CREATE OR REPLACE FUNCTION public.create_loyalty_for_merchant(merchant_id uuid)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    m RECORD;
    new_loyalty_program_id UUID;
    default_customer_id UUID;
BEGIN
    SELECT * INTO m FROM merchants WHERE id = merchant_id;

    -- Only proceed if eligible
    IF m.is_loyalty_program_eligible THEN
        -- Insert loyalty program (matches current schema)
        INSERT INTO loyalty_programs (
            merchant_id,
            loyalty_program_id,
            current_punches,
            status
        )
        VALUES (
            m.id,
            gen_random_uuid(),
            0,
            'active'
        )
        RETURNING id INTO new_loyalty_program_id;

        -- Create default customer
        INSERT INTO customers (
            merchant_id,
            full_name,
            phone_number,
            zone,
            created_at
        )
        VALUES (
            m.id,
            m.name || ' Default Customer',
            'N/A_' || m.id::text,
            'Default',
            NOW()
        )
        ON CONFLICT (phone_number) DO NOTHING
        RETURNING id INTO default_customer_id;

        -- Create loyalty card
        IF default_customer_id IS NOT NULL THEN
            INSERT INTO loyalty_cards (
                customer_id,
                merchant_id,
                loyalty_program_id,
                current_punches,
                status
            )
            VALUES (
                default_customer_id,
                m.id,
                new_loyalty_program_id,
                0,
                'active'
            );
        END IF;
    END IF;
END;
$function$
;

CREATE OR REPLACE FUNCTION public.generate_new_code(p_customer_id uuid, p_merchant_id uuid, p_loyalty_card_id uuid)
 RETURNS text
 LANGUAGE plpgsql
AS $function$
     DECLARE
        new_code TEXT;
    BEGIN
        -- Generate a unique code (e.g., a UUID or a shorter alphanumeric string)
        -- For simplicity, we'll use a UUID here. You might want a shorter, more human-readable code later.
        new_code := gen_random_uuid();

        -- Insert the new code into the codes table
        INSERT INTO codes (code_string, customer_id,
      merchant_id, loyalty_card_id, status)
        VALUES (new_code, p_customer_id, p_merchant_id,
      p_loyalty_card_id, 'active');

        RETURN new_code;
    END;
    $function$
;

CREATE OR REPLACE FUNCTION public.process_code_redemption(p_code_string text)
 RETURNS TABLE(status text, message text, customer_id uuid, merchant_id uuid, loyalty_card_id uuid, current_punches integer, punches_needed integer, reward_description text)
 LANGUAGE plpgsql
AS $function$
     DECLARE
         v_code_record codes%ROWTYPE;
         v_loyalty_card_record loyalty_cards%ROWTYPE;
         v_loyalty_program_record loyalty_programs%
       ROWTYPE;
         v_new_code TEXT;
     BEGIN
         -- 1. Validate the code
         SELECT * INTO v_code_record
         FROM codes
         WHERE code_string = p_code_string;

         IF NOT FOUND THEN
             RETURN QUERY SELECT 'error', 'Code not
       found', NULL, NULL, NULL, NULL, NULL, NULL;
             RETURN;
         END IF;

         IF v_code_record.status = 'used' THEN
             RETURN QUERY SELECT 'error', 'Code already
       used', v_code_record.customer_id,
       v_code_record.merchant_id,
       v_code_record.loyalty_card_id, NULL, NULL, NULL;
             RETURN;
         END IF;

         IF v_code_record.status = 'invalid' THEN
             RETURN QUERY SELECT 'error', 'Code is
       invalid', v_code_record.customer_id,
       v_code_record.merchant_id,
       v_code_record.loyalty_card_id, NULL, NULL, NULL;
             RETURN;
         END IF;

         -- 2. Mark the code as used
         UPDATE codes
         SET status = 'used', used_at = NOW()
         WHERE id = v_code_record.id;

         -- 3. Get loyalty card and program details
         SELECT * INTO v_loyalty_card_record
         FROM loyalty_cards
         WHERE id = v_code_record.loyalty_card_id;

         SELECT * INTO v_loyalty_program_record
         FROM loyalty_programs
         WHERE id =
       v_loyalty_card_record.loyalty_program_id;

         -- 4. Increment punches
         UPDATE loyalty_cards
         SET current_punches =
       v_loyalty_card_record.current_punches + 1
         WHERE id = v_loyalty_card_record.id
         RETURNING * INTO v_loyalty_card_record; -- Get the updated record

         -- 5. Check for reward completion
         IF v_loyalty_card_record.current_punches >=
       v_loyalty_program_record.punches_required THEN
             -- Card is complete, mark it as such
             UPDATE loyalty_cards
             SET status = 'completed'
             WHERE id = v_loyalty_card_record.id;

             -- Generate a new loyalty card for the customer
             INSERT INTO loyalty_cards (customer_id,
       merchant_id, loyalty_program_id, current_punches,
       status)
             VALUES (v_loyalty_card_record.customer_id,
       v_loyalty_card_record.merchant_id,
       v_loyalty_card_record.loyalty_program_id, 0,
       'active');

             -- Generate a new code for the new card (this will be manually sent via WhatsApp)
             v_new_code := generate_new_code(
                 v_loyalty_card_record.customer_id,
                 v_loyalty_card_record.merchant_id,
                 (SELECT id FROM loyalty_cards WHERE
       customer_id = v_loyalty_card_record.customer_id AND
       status = 'active' ORDER BY generated_at DESC LIMIT
       1) -- Get the ID of the newly created active card
             );

             RETURN QUERY SELECT 'success', 'Card
       complete! Reward: ' ||
       v_loyalty_program_record.reward_description || '.
       New code generated: ' || v_new_code,
                            v_code_record.customer_id,
       v_code_record.merchant_id,
       v_code_record.loyalty_card_id,

       v_loyalty_card_record.current_punches, 0,
       v_loyalty_program_record.reward_description;
         ELSE
             -- Card not complete, generate new code for next punch
             v_new_code := generate_new_code(
                 v_loyalty_card_record.customer_id,
                 v_loyalty_card_record.merchant_id,
                 v_loyalty_card_record.id
             );

             RETURN QUERY SELECT 'success', 'Punch
       added! Customer has ' ||
       (v_loyalty_program_record.punches_required -
       v_loyalty_card_record.current_punches) || ' punches
       left. New code generated: ' || v_new_code,
                            v_code_record.customer_id,
       v_code_record.merchant_id,
       v_code_record.loyalty_card_id,

       v_loyalty_card_record.current_punches,
       (v_loyalty_program_record.punches_required -
       v_loyalty_card_record.current_punches),
       v_loyalty_program_record.reward_description;
         END IF;

     EXCEPTION
         WHEN OTHERS THEN
             RETURN QUERY SELECT 'error', SQLERRM, NULL,
       NULL, NULL, NULL, NULL, NULL;
     END;
    $function$
;

CREATE OR REPLACE FUNCTION public.redeem_code()
 RETURNS trigger
 LANGUAGE plpgsql
 SECURITY DEFINER
AS $function$
DECLARE
    current_card_punches INT;
    punches_required INT;
    free_reward_code TEXT;
BEGIN
    -- Only process if the new code is unused
    IF NEW.status = 'unused' THEN
        -- Mark code as used
        UPDATE codes
        SET status = 'used', used_at = NOW()
        WHERE id = NEW.id;

        -- Get current punches and required punches
        SELECT lc.current_punches, lp.punches_required
        INTO current_card_punches, punches_required
        FROM loyalty_cards lc
        JOIN loyalty_programs lp
        ON lc.loyalty_program_id = lp.id
        WHERE lc.id = NEW.loyalty_card_id;

        -- Increment punch count
        UPDATE loyalty_cards
        SET current_punches = current_punches + 1
        WHERE id = NEW.loyalty_card_id;

        -- Check if reward threshold reached
        IF current_card_punches + 1 >= punches_required THEN
            -- Generate free reward code
            free_reward_code := gen_random_uuid()::text;

            INSERT INTO codes (code_string, customer_id, merchant_id, loyalty_card_id, status, generated_at)
            VALUES (free_reward_code, NEW.customer_id, NEW.merchant_id, NEW.loyalty_card_id, 'unused', NOW());

            -- Mark loyalty card as redeemed
            UPDATE loyalty_cards
            SET status = 'redeemed'
            WHERE id = NEW.loyalty_card_id;

            -- Optional: send WhatsApp message in Romanian via your backend
            -- "Felicitări! Ai colectat 6 ștampile. Scanează acest cod pentru a primi cafeaua gratuită: <free_reward_code>"
        END IF;
    END IF;

    RETURN NEW;
END;
$function$
;

DROP TRIGGER IF EXISTS trigger_redeem_code ON public.codes;
CREATE TRIGGER trigger_redeem_code AFTER INSERT ON public.codes FOR EACH ROW WHEN ((new.status = 'unused'::text)) EXECUTE FUNCTION redeem_code();