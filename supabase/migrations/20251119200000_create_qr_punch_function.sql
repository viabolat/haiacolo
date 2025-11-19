-- This migration creates a new function `punch_loyalty_card`
-- which is designed to be called with a direct loyalty_card_id (from a QR code).
-- This is simpler and more robust than the old code-based redemption system.

CREATE OR REPLACE FUNCTION public.punch_loyalty_card(p_loyalty_card_id uuid)
RETURNS TABLE(
    status text,
    message text
)
LANGUAGE plpgsql
AS $function$
DECLARE
    v_loyalty_card loyalty_cards%ROWTYPE;
    v_loyalty_program loyalty_programs%ROWTYPE;
BEGIN
    -- Find the loyalty card and lock it for update to prevent race conditions
    SELECT * INTO v_loyalty_card FROM public.loyalty_cards WHERE id = p_loyalty_card_id FOR UPDATE;

    IF NOT FOUND THEN
        RETURN QUERY SELECT 'error', 'Loyalty card not found.';
        RETURN;
    END IF;

    IF v_loyalty_card.status <> 'active' THEN
        RETURN QUERY SELECT 'error', 'This loyalty card is not active. It might be already completed.';
        RETURN;
    END IF;

    -- Get the loyalty program details to know how many punches are required
    SELECT * INTO v_loyalty_program FROM public.loyalty_programs WHERE id = v_loyalty_card.loyalty_program_id;

    -- Increment the punches
    UPDATE public.loyalty_cards
    SET current_punches = v_loyalty_card.current_punches + 1
    WHERE id = p_loyalty_card_id
    RETURNING * INTO v_loyalty_card;

    -- Check if the card is now complete
    IF v_loyalty_card.current_punches >= v_loyalty_program.punches_required THEN
        -- Mark the current card as completed
        UPDATE public.loyalty_cards SET status = 'completed' WHERE id = p_loyalty_card_id;

        -- Create a new, fresh card for the customer to start over
        INSERT INTO public.loyalty_cards (customer_id, merchant_id, loyalty_program_id, current_punches, status)
        VALUES (v_loyalty_card.customer_id, v_loyalty_card.merchant_id, v_loyalty_card.loyalty_program_id, 0, 'active');

        RETURN QUERY SELECT 'success', 'Card complete! Reward earned: ' || v_loyalty_program.reward_description || '. A new card has been automatically started for the customer.';
    ELSE
        -- Card is not yet complete, just return the progress
        RETURN QUERY SELECT 'success', 'Punch added! The customer has ' || (v_loyalty_program.punches_required - v_loyalty_card.current_punches) || ' punches left to get their reward.';
    END IF;

EXCEPTION
    WHEN OTHERS THEN
        RETURN QUERY SELECT 'error', 'An unexpected error occurred: ' || SQLERRM;
END;
$function$;
