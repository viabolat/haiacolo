-- First, fix the function that creates a default customer for a loyalty program.
-- The 'zone' column is an array, so we must insert an array value.
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
    IF m.is_loyalty_program_eligible THEN
        INSERT INTO loyalty_programs (merchant_id, type, reward_description, punches_required, perk_description)
        VALUES (m.id, 'stamp_card', 'Default reward', 6, 'Default perk')
        RETURNING id INTO new_loyalty_program_id;
        
        INSERT INTO customers (merchant_id, full_name, phone_number, zone, created_at)
        VALUES (m.id, m.name || ' Default Customer', 'N/A_' || m.id::text, ARRAY['Default'], NOW())
        ON CONFLICT (phone_number) DO NOTHING
        RETURNING id INTO default_customer_id;
        
        IF default_customer_id IS NOT NULL THEN
            INSERT INTO loyalty_cards (customer_id, merchant_id, loyalty_program_id, current_punches, status)
            VALUES (default_customer_id, m.id, new_loyalty_program_id, 0, 'active');
        END IF;
    END IF;
END;
$function$;

-- Set the default for is_loyalty_program_eligible to true for all new merchants
ALTER TABLE public.merchants ALTER COLUMN is_loyalty_program_eligible SET DEFAULT true;

-- Update all existing merchants to be eligible
UPDATE public.merchants SET is_loyalty_program_eligible = true WHERE is_loyalty_program_eligible = false;

-- Loop through all merchants and create a loyalty program for those who don't have one
DO $$
DECLARE
    merchant_record RECORD;
BEGIN
    FOR merchant_record IN SELECT id FROM public.merchants
    LOOP
        -- Check if a loyalty program already exists for this merchant
        IF NOT EXISTS (SELECT 1 FROM public.loyalty_programs WHERE merchant_id = merchant_record.id) THEN
            -- If not, create one by calling the now-corrected function
            PERFORM public.create_loyalty_for_merchant(merchant_record.id);
        END IF;
    END LOOP;
END $$;
