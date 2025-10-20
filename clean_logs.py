import os
import logging
import argparse # For command-line arguments
from dotenv import load_dotenv
from supabase import create_client, Client
from datetime import datetime, timedelta, timezone

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Load environment variables from .env file
load_dotenv()

# --- CONFIGURATION ---

# The table to clean up
TABLE_NAME = os.environ.get("CLEANUP_TABLE_NAME", "sent_deals_log")

# The timestamp column to check against
TIMESTAMP_COLUMN = os.environ.get("CLEANUP_TIMESTAMP_COLUMN", "sent_at")

# How many days of logs to keep. Records older than this will be deleted.
DAYS_TO_KEEP = int(os.environ.get("CLEANUP_DAYS_TO_KEEP", 30))

# --- SUPABASE CLIENT INITIALIZATION ---

SUPABASE_URL = os.environ.get("SUPABASE_URL")
# IMPORTANT: This script requires the Service Role Key to perform deletions.
# In your project, this is stored in the SUPABASE_API_KEY environment variable.
SUPABASE_SERVICE_KEY = os.environ.get("SUPABASE_API_KEY")

if not SUPABASE_URL or not SUPABASE_SERVICE_KEY:
    logger.error("SUPABASE_URL and SUPABASE_API_KEY (Service Role Key) must be set in your environment.")
    raise ValueError(
        "SUPABASE_URL and SUPABASE_API_KEY (Service Role Key) must be set in your environment."
    )

supabase: Client = create_client(SUPABASE_URL, SUPABASE_SERVICE_KEY)


def cleanup_old_logs(dry_run: bool = False):
    """
    Deletes records from the specified table that are older than DAYS_TO_KEEP.
    If dry_run is True, it only reports what would be deleted without performing the deletion.
    """
    logger.info(f"--- Starting cleanup for table: '{TABLE_NAME}' ---")
    if dry_run:
        logger.info("--- DRY RUN: No actual deletions will be performed. ---")

    # 1. Calculate the cutoff date
    cutoff_date = datetime.now(timezone.utc) - timedelta(days=DAYS_TO_KEEP)
    # Format for Supabase query (ISO 8601)
    cutoff_iso = cutoff_date.isoformat()

    logger.info(
        f"Targeting records created before: {cutoff_date.strftime('%Y-%m-%d %H:%M:%S UTC')}"
    )

    try:
        # 2. Count records to be deleted (for dry run or confirmation)
        count_response = supabase.table(TABLE_NAME).select("id", count="exact").lt(TIMESTAMP_COLUMN, cutoff_iso).execute()
        records_to_delete_count = count_response.count if count_response.count is not None else 0
        
        if records_to_delete_count == 0:
            logger.info(f"No old records found in '{TABLE_NAME}' to delete.")
            return

        logger.info(f"Found {records_to_delete_count} records to delete from '{TABLE_NAME}'.")

        if dry_run:
            logger.info("Dry run complete. No records were deleted.")
            return

        # 3. Perform the deletion using the Supabase client
        # The 'lt' filter means 'less than'
        data, count = (
            supabase.table(TABLE_NAME)
            .delete(count="exact")
            .lt(TIMESTAMP_COLUMN, cutoff_iso)
            .execute()
        )

        # The 'count' variable from the response contains the number of deleted rows
        deleted_count = count if count is not None else "Unknown"

        logger.info(f"✅ Success! Deleted {deleted_count} records from '{TABLE_NAME}'.")

    except Exception as e:
        logger.error(f"❌ An error occurred during cleanup: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Clean up old logs from Supabase.")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="If set, no actual deletions will be performed, only a report of what would be deleted."
    )
    args = parser.parse_args()
    cleanup_old_logs(dry_run=args.dry_run)
