-- Update max featured products limit from 6 to 10 (Tobias's original spec)

CREATE OR REPLACE FUNCTION retail.enforce_max_selections()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    current_count integer;
BEGIN
    SELECT count(*) INTO current_count
    FROM retail.professional_selections
    WHERE professional_id = NEW.professional_id;

    IF current_count >= 10 THEN
        RAISE EXCEPTION 'Professional may select a maximum of 10 products'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;
