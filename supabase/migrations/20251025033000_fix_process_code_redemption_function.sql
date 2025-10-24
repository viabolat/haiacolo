-- This migration updates the process_code_redemption function to handle errors correctly.

CREATE OR REPLACE FUNCTION public.process_code_redemption(p_code_string text) 
RETURNS TABLE(
    status text, 
    message text, 
    customer_id uuid, 
    merchant_id uuid, 
    loyalty_card_id uuid, 
    current_punches integer, 
    punches_needed integer, 
    reward_description text
) 
LANGUAGE plpgsql
AS $function$
DECLARE
    v_code_record codes%ROWTYPE;
    v_loyalty_card_record loyalty_cards%ROWTYPE;
    v_loyalty_program_record loyalty_programs%ROWTYPE;
    v_new_code TEXT;
BEGIN
    SELECT * INTO v_code_record FROM codes WHERE code_string = p_code_string;
    IF NOT FOUND THEN 
        RETURN QUERY SELECT 'error', 'Code not found', gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), NULL, NULL, NULL; 
        RETURN; 
    END IF;
    
    IF v_code_record.status = 'used' THEN 
        RETURN QUERY SELECT 'error', 'Code already used', v_code_record.customer_id, v_code_record.merchant_id, v_code_record.loyalty_card_id, NULL, NULL, NULL; 
        RETURN; 
    END IF;
    
    IF v_code_record.status = 'invalid' THEN 
        RETURN QUERY SELECT 'error', 'Code is invalid', v_code_record.customer_id, v_code_record.merchant_id, v_code_record.loyalty_card_id, NULL, NULL, NULL; 
        RETURN; 
    END IF;
    
    UPDATE codes SET status = 'used', used_at = NOW() WHERE id = v_code_record.id;
    
    SELECT * INTO v_loyalty_card_record FROM loyalty_cards WHERE id = v_code_record.loyalty_card_id;
    SELECT * INTO v_loyalty_program_record FROM loyalty_programs WHERE id = v_loyalty_card_record.loyalty_program_id;
    
    UPDATE loyalty_cards SET current_punches = v_loyalty_card_record.current_punches + 1 WHERE id = v_loyalty_card_record.id RETURNING * INTO v_loyalty_card_record;
    
    IF v_loyalty_card_record.current_punches >= v_loyalty_program_record.punches_required THEN
        UPDATE loyalty_cards SET status = 'completed' WHERE id = v_loyalty_card_record.id;
        INSERT INTO loyalty_cards (customer_id, merchant_id, loyalty_program_id, current_punches, status)
        VALUES (v_loyalty_card_record.customer_id, v_loyalty_card_record.merchant_id, v_loyalty_card_record.loyalty_program_id, 0, 'active');
        v_new_code := generate_new_code(v_loyalty_card_record.customer_id, v_loyalty_card_record.merchant_id, (SELECT id FROM loyalty_cards WHERE customer_id = v_loyalty_card_record.customer_id AND status = 'active' ORDER BY id DESC LIMIT 1));
        RETURN QUERY SELECT 'success', 'Card complete! Reward: ' || v_loyalty_program_record.reward_description || '. New code generated: ' || v_new_code, v_code_record.customer_id, v_code_record.merchant_id, v_code_record.loyalty_card_id, v_loyalty_card_record.current_punches, 0, v_loyalty_program_record.reward_description;
    ELSE
        v_new_code := generate_new_code(v_loyalty_card_record.customer_id, v_loyalty_card_record.merchant_id, v_loyalty_card_record.id);
        RETURN QUERY SELECT 'success', 'Punch added! Customer has ' || (v_loyalty_program_record.punches_required - v_loyalty_card_record.current_punches) || ' punches left. New code generated: ' || v_new_code, v_code_record.customer_id, v_code_record.merchant_id, v_code_record.loyalty_card_id, v_loyalty_card_record.current_punches, (v_loyalty_program_record.punches_required - v_loyalty_card_record.current_punches), v_loyalty_program_record.reward_description;
    END IF;
EXCEPTION 
    WHEN OTHERS THEN 
        RETURN QUERY SELECT 'error', SQLERRM, gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), NULL, NULL, NULL;
END;
$function$;