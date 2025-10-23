
ALTER TABLE public.merchants
ADD COLUMN approval_token_hash TEXT,
ADD COLUMN approval_token_expires_at TIMESTAMPTZ;
