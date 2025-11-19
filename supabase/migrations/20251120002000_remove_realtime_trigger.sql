-- This migration removes the realtime broadcast trigger and function
-- as they are not supported in the environment.
-- The application will be reverted to a polling mechanism.

DROP TRIGGER IF EXISTS on_loyalty_card_update ON public.loyalty_cards;

DROP FUNCTION IF EXISTS public.broadcast_loyalty_card_update();
