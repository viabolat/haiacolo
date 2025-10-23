import os
import time
import random
import urllib.parse
from pathlib import Path
import requests
import logging  # Added logging import
from dotenv import load_dotenv
from supabase import create_client, Client
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException, NoSuchElementException

# Configure logging
logging.basicConfig(
    level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)

# --- SETUP ---
load_dotenv()

# Supabase Client
SUPABASE_URL = os.environ.get("SUPABASE_URL")
SUPABASE_SERVICE_KEY = os.environ.get("SUPABASE_API_KEY")
if not SUPABASE_URL or not SUPABASE_SERVICE_KEY:
    logger.error("Supabase credentials must be set in .env file.")
    raise ValueError("Supabase credentials must be set in .env file.")
supabase: Client = create_client(SUPABASE_URL, SUPABASE_SERVICE_KEY)

# Directory to store downloaded QR codes temporarily
TEMP_QR_DIR = Path("temp_qrs")
TEMP_QR_DIR.mkdir(exist_ok=True)

# --- MESSAGE VARIATION ---
# A list of different greetings to prepend to the message.
# The script will cycle through these to make each message unique.
MESSAGE_GREETINGS = [
    "Salut de la HaiAcolo! 👋 Ai o ofertă specială:",
    "Salut! Ofertă nouă de la HaiAcolo pentru tine:",
    "O nouă surpriză de la HaiAcolo! Primești o ofertă specială:",
    "Ești gata de o nouă ofertă? HaiAcolo ți-a pregătit ceva special:",
    "Bună! Avem o ofertă excelentă pentru tine de la HaiAcolo:",
]

# --- WHATSAPP WEB SELECTORS ---
# These selectors are based on the HTML structure of WhatsApp Web.
# If the script fails to find elements, these are the first things to check and update.
SELECTORS = {
    "main_chat_panel": (By.ID, "pane-side"),
    "attach_button": (By.CSS_SELECTOR, 'div[title="Attach"]'),
    "image_video_input": (By.XPATH, '//input[@accept="image/*,video/mp4,video/3gpp,video/quicktime"]'),
    "send_button": (By.CSS_SELECTOR, 'span[data-icon="send-light"]'),
}
# --- SELENIUM WEBDRIVER SETUP ---


def get_driver(headless=False):
    """Initializes the Selenium WebDriver with a persistent user profile."""
    options = webdriver.ChromeOptions()
    # This saves your session so you don't have to log in to WhatsApp every time
    options.add_argument("--user-data-dir=./whatsapp_session")
    if headless:
        options.add_argument("--headless")
        options.add_argument("--window-size=1920,1080")
        options.add_argument("--no-sandbox")  # Recommended for Linux environments
    driver = webdriver.Chrome(options=options)
    return driver


def fetch_pending_deals(limit=10):
    """Fetches a batch of pending deals from Supabase."""
    try:
        response = (
            supabase.table("pending_sends")
            .select("*")
            .eq("status", "pending")
            .order("created_at", ascending=True)
            .limit(limit)
            .execute()
        )
        return response.data if response.data else []
    except Exception as e:
        logger.error(f"❌ Error fetching pending deals: {e}")
        raise  # Re-raise the exception to be handled by the caller


def download_qr_code(url: str, short_code: str) -> Path | None:
    """Downloads a QR code image from a URL and saves it locally."""
    try:
        response = requests.get(url, stream=True)
        response.raise_for_status()
        file_path = TEMP_QR_DIR / f"{short_code}.png"
        with open(file_path, "wb") as f:
            for chunk in response.iter_content(chunk_size=8192):
                f.write(chunk)
        logger.info(f"✅ QR code '{short_code}.png' downloaded successfully.")
        return file_path
    except requests.exceptions.RequestException as e:
        logger.error(f"❌ Failed to download QR code from {url}: {e}")
        return None


def send_whatsapp_message(driver, phone: str, message: str, image_path: Path):
    """Navigates WhatsApp Web to send a message with an image."""
    # NOTE: This function relies on the structure of WhatsApp Web's HTML.
    # If WhatsApp updates its website, the selectors (e.g., 'div[title="Attach"]')
    # may need to be updated to match the new structure.
    try:
        # 1. Open chat with the user with the full message pre-filled
        encoded_message = urllib.parse.quote(message)
        url = f"https://web.whatsapp.com/send?phone={phone}&text={encoded_message}"
        driver.get(url)

        # 2. Wait for the attach button and click it
        # Using a more specific selector by 'title' which is less likely to change.
        attach_button = WebDriverWait(driver, 30).until(EC.element_to_be_clickable(SELECTORS["attach_button"]))
        attach_button.click()
        # Wait for the 'Photos & videos' input to be present
        WebDriverWait(driver, 10).until(
            EC.presence_of_element_located(SELECTORS["image_video_input"])
        )

        # 3. Find the 'Photos & videos' input and upload the file
        image_input = driver.find_element(
            *SELECTORS["image_video_input"]
        )
        image_input.send_keys(str(image_path.resolve()))  # Use absolute path

        # 4. Wait for the send button in the image preview and click it
        send_button = WebDriverWait(driver, 20).until(
            EC.element_to_be_clickable(SELECTORS["send_button"])
        )
        send_button.click()
        logger.info(f"🚀 Message and QR code sent to {phone}.")
        return True

    except (TimeoutException, NoSuchElementException) as e:
        logger.error(
            f"❌ Failed to send message to {phone}. Maybe the number is invalid or WhatsApp UI changed."
        )
        logger.error(f"   Error: {e}")
        return False


def update_deal_status(deal_id: int, status: str):
    """Updates the status of a deal in the database."""
    try:
        supabase.table("pending_sends").update({"status": status}).eq(
            "id", deal_id
        ).execute()
        logger.info(f"✅ Deal ID {deal_id} status updated to '{status}'.")
    except Exception as e:
        logger.error(f"❌ Failed to update status for deal ID {deal_id}: {e}")


def log_sent_deal(customer_id: str, merchant_id: str):
    """Logs a successfully sent deal to the sent_deals_log table."""
    try:
        supabase.table("sent_deals_log").insert(
            {"customer_id": customer_id, "merchant_id": merchant_id}
        ).execute()
        logger.info(
            f"✅ Successfully logged sent deal for customer {customer_id} and merchant {merchant_id}."
        )
    except Exception as e:
        # This is a non-critical error; the message was sent. Log it.
        logger.error(
            f"⚠️ Failed to log sent deal for customer {customer_id}, merchant {merchant_id}: {e}"
        )


def main():
    logger.info("--- Starting WhatsApp Automation Sender ---")
    # Set headless=True to run in the background. Set to False for the initial login.
    driver = get_driver(headless=True)
    driver.get("https://web.whatsapp.com")

    # --- AUTOMATIC LOGIN DETECTION ---
    # Instead of pausing, we wait for the main chat panel to appear.
    try:
        logger.info("⏳ Waiting for WhatsApp Web to load and log in...")
        WebDriverWait(driver, 60).until(EC.presence_of_element_located(SELECTORS["main_chat_panel"]))
        logger.info("✅ Login successful, proceeding with sending.")
    except TimeoutException:
        logger.error(
            "❌ Login timed out. Please run the script with 'headless=False' once to scan the QR code and create a session."
        )
        driver.quit()
        return

    deals = fetch_pending_deals()
    if not deals:
        logger.info("✨ No pending deals to send. All done!")
        driver.quit()
        return

    for i, deal in enumerate(deals):
        logger.info(
            f"\n--- Processing deal ID: {deal['id']} for {deal['phone_number']} ---"
        )

        # --- MESSAGE VARIATION LOGIC ---
        # Cycle through the greetings list
        greeting = MESSAGE_GREETINGS[i % len(MESSAGE_GREETINGS)]

        # The original message from the DB starts with "Salut de la HaiAcolo!чением..."
        # We'll remove that first line and prepend our new, varied greeting.
        message_lines = deal["message"].split("\n\n")
        core_message = "\n\n".join(
            message_lines[1:]
        )  # Get everything except the first line
        final_message = f"{greeting}\n\n{core_message}"

        # Download the QR code for this deal
        qr_path = download_qr_code(deal["qr_code_url"], deal["short_code"])
        if not qr_path:
            update_deal_status(deal["id"], "failed")
            continue

        # Send the message
        success = send_whatsapp_message(
            driver, deal["phone_number"], final_message, qr_path
        )

        if success:
            update_deal_status(deal["id"], "sent")
            log_sent_deal(deal["customer_id"], deal["merchant_id"])
        else:
            update_deal_status(deal["id"], "failed")

        # Clean up the downloaded QR code
        qr_path.unlink()

        # Wait for a random time to avoid being too bot-like
        sleep_time = random.uniform(15, 30)
        logger.info(f"😴 Waiting for {sleep_time:.1f} seconds before next message...")
        time.sleep(sleep_time)

    logger.info("\n--- All deals in the batch have been processed. ---")
    driver.quit()


if __name__ == "__main__":
    main()
