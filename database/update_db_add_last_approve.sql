-- Add last_approve column to requests table for shared approval code
ALTER TABLE requests ADD COLUMN IF NOT EXISTS last_approve VARCHAR(50) DEFAULT NULL AFTER status;

-- Add index for faster querying
CREATE INDEX IF NOT EXISTS idx_last_approve ON requests(last_approve);
