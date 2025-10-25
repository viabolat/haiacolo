import os
from dotenv import load_dotenv
import shutil
import json

load_dotenv()
import random
import string
import urllib.parse
import qrcode
from qrcode import constants
from pathlib import Path
from qrcode.image.pil import PilImage
from PIL import Image, ImageDraw, ImageFont
from supabase import create_client, Client
import smtplib
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from typing import List, Dict, Set, Tuple, Any, Optional

# --- CONFIGURATION ---

VERIFY_BASE_URL = os.environ.get("VERIFY_BASE_URL", "https://haiacolo.ro/verify")
SENDER_CONSOLE_URL = os.environ.get(
    "SENDER_CONSOLE_URL", "https://haiacolo.ro/sender.html"
)
QR_CODE_DIR = "qrcodes"

EMAIL_HOST = os.environ.get("EMAIL_HOST")
EMAIL_PORT = os.environ.get("EMAIL_PORT")
EMAIL_HOST_USER = os.environ.get("EMAIL_HOST_USER")
EMAIL_HOST_PASSWORD = os.environ.get("EMAIL_HOST_PASSWORD")
EMAIL_RECIPIENT = os.environ.get("EMAIL_RECIPIENT")

# Initialize Supabase client
SUPABASE_URL: Optional[str] = os.environ.get("SUPABASE_URL")
SUPABASE_API_KEY: Optional[str] = os.environ.get("SUPABASE_API_KEY")

if not SUPABASE_URL or not SUPABASE_API_KEY:
    raise ValueError("Supabase URL and API Key must be set in your .env file.")

try:
    supabase: Client = create_client(SUPABASE_URL, SUPABASE_API_KEY)
except Exception as e:
    print(f"FATAL: Could not initialize Supabase client: {e}")
    raise


def generate_short_code(length: int = 6) -> str:
    """Generates a short, unique, human-readable code."""
    chars = string.ascii_uppercase.replace("I", "").replace("L", "").replace(
        "O", ""
    ) + string.digits.replace("0", "").replace("1", "")
    return "".join(random.choice(chars) for _ in range(length))


def _supabase_fetch(
    table_name: str, query: str, filters: Optional[Dict[str, Any]] = None
) -> List[Dict[str, Any]]:
    """Helper function to fetch data from Supabase and handle errors."""
    try:
        query_builder = supabase.table(table_name).select(query)
        if filters:
            for key, value in filters.items():
                query_builder = query_builder.eq(key, value)
        response = query_builder.execute()
        return response.data if response.data else []
    except Exception as e:
        print(f"Error fetching from {table_name}: {e}")
        raise


def fetch_customers() -> List[Dict[str, Any]]:
    """Fetches active customers with their preferences."""
    return _supabase_fetch("customers", "id, phone_number, zone, interests")


def fetch_active_merchants() -> List[Dict[str, Any]]:
    """Fetches merchants with an active subscription."""
    return _supabase_fetch(
        "merchants",
        "id, name, location, business_type, offer_description, address",
        {"subscription_status": "active"},
    )


def fetch_sent_deal_logs() -> Set[Tuple[str, str]]:
    """Fetches records of deals already sent to customers to prevent resending."""
    data = _supabase_fetch("sent_deals_log", "customer_id, merchant_id")
    return set((str(item["customer_id"]), str(item["merchant_id"])) for item in data)


def _generate_qr_image(data: str) -> Image.Image:
    """Generates a QR code image from the given data."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=constants.ERROR_CORRECT_H,
        box_size=10,
        border=4,
    )
    qr.add_data(data)
    qr.make(fit=True)
    return qr.make_image(image_factory=PilImage).convert("RGB")


def _add_logo_to_qr(qr_img: Image.Image) -> Image.Image:
    """Adds a logo to the center of the QR code image."""
    qr_width, qr_height = qr_img.size
    logo_path = None
    for ext in ["png", "PNG", "jpg", "jpeg"]:
        potential_path = f"public/logo.{ext}"
        if os.path.exists(potential_path):
            logo_path = potential_path
            break

    if not logo_path:
        print(
            "Logo file not found in public directory. QR code generated without logo."
        )
        return qr_img

    try:
        logo = Image.open(logo_path)
        if logo.mode == "RGBA":
            background = Image.new("RGB", logo.size, (255, 255, 255))
            background.paste(logo, mask=logo.split()[3])
            logo = background
        elif logo.mode != "RGB":
            logo = logo.convert("RGB")

        logo_size = int(min(qr_width, qr_height) * 0.22)
        logo.thumbnail((logo_size, logo_size), Image.Resampling.LANCZOS)

        border_size = 8
        logo_with_border = Image.new(
            "RGB",
            (logo.width + border_size * 2, logo.height + border_size * 2),
            "white",
        )
        logo_with_border.paste(logo, (border_size, border_size))

        logo_x = (qr_width - logo_with_border.width) // 2
        logo_y = (qr_height - logo_with_border.height) // 2
        qr_img.paste(logo_with_border, (logo_x, logo_y))
        print(f"Logo successfully added to QR code from {logo_path}")
    except Exception as logo_error:
        print(f"Could not add logo to QR code: {logo_error}")

    return qr_img


def _add_text_to_image(image: Image.Image, text: str) -> Image.Image:
    """Adds text to the bottom of the image."""
    qr_width, qr_height = image.size
    padding = 20
    text_height = 60
    new_img = Image.new("RGB", (qr_width, qr_height + text_height + padding), "white")
    new_img.paste(image, (0, 0))

    draw = ImageDraw.Draw(new_img)
    font = None
    font_size = 36
    font_paths = ["DejaVuSans.ttf", "arial.ttf", "Helvetica.ttf"]
    for font_path in font_paths:
        try:
            font = ImageFont.truetype(font_path, font_size)
            break
        except IOError:
            continue

    if not font:
        font = ImageFont.load_default()

    text_center_x = qr_width / 2
    text_center_y = qr_height + padding + (text_height / 2)
    draw.text(
        (text_center_x, text_center_y), text, fill="black", font=font, anchor="mm"
    )
    return new_img


def create_qr_with_text(data: str, text: str, file_path: str):
    """
    Generates a QR code with a centered logo and saves it as an image
    with the provided text written below it.
    """
    try:
        qr_img = _generate_qr_image(data)
        qr_img_with_logo = _add_logo_to_qr(qr_img)
        final_image = _add_text_to_image(qr_img_with_logo, text)
        final_image.save(file_path)
    except Exception as e:
        print(f"Error creating QR code image for '{text}': {e}")


def send_notification_email(subject: str, body: str):
    """Constructs and sends a simple notification email."""
    if not all(
        [EMAIL_HOST, EMAIL_PORT, EMAIL_HOST_USER, EMAIL_HOST_PASSWORD, EMAIL_RECIPIENT]
    ):
        print("Email configuration is incomplete. Skipping email notification.")
        return

    assert EMAIL_HOST is not None
    assert EMAIL_PORT is not None
    assert EMAIL_HOST_USER is not None
    assert EMAIL_HOST_PASSWORD is not None
    assert EMAIL_RECIPIENT is not None

    msg = MIMEMultipart()
    msg["From"] = EMAIL_HOST_USER
    msg["To"] = EMAIL_RECIPIENT
    msg["Subject"] = subject
    msg.attach(MIMEText(body, "plain"))

    try:
        port = int(EMAIL_PORT)
        if port == 465:
            server = smtplib.SMTP_SSL(EMAIL_HOST, port)
        else:
            server = smtplib.SMTP(EMAIL_HOST, port)
            server.starttls()
        server.login(EMAIL_HOST_USER, EMAIL_HOST_PASSWORD)
        text = msg.as_string()
        server.sendmail(EMAIL_HOST_USER, EMAIL_RECIPIENT, text)
        server.quit()
        print(f"Successfully sent notification email to {EMAIL_RECIPIENT}")
    except Exception as e:
        print(f"Failed to send notification email: {e}")


def run_deal_matching():
    """
    Main function to find matching deals, generate QR codes, save them to Supabase Storage,
    and queue them for sending in the 'pending_sends' table.
    """
    print("Starting deal matching process...")
    print("=" * 60)
    Path(QR_CODE_DIR).mkdir(exist_ok=True)
    print(f"Temporary QR codes will be saved in the '{QR_CODE_DIR}' directory.")

    try:
        customers = fetch_customers()
        merchants = fetch_active_merchants()
        sent_deal_logs = fetch_sent_deal_logs()

        print(f"\n📊 Data Summary:")
        print(f"  - Total customers: {len(customers)}")
        print(f"  - Active merchants: {len(merchants)}")
        print(f"  - Already sent deals: {len(sent_deal_logs)}")
        print()

        if not customers:
            print("❌ No customers found. Exiting.")
            return

        if not merchants:
            print("❌ No active merchants found. Exiting.")
            return

        pending_sends_to_insert: List[Dict[str, Any]] = []
        codes_to_log: List[Dict[str, Any]] = []
        total_eligible_count = 0
        BATCH_SIZE = 500  # Process inserts in chunks to avoid timeouts

        for merchant in merchants:
            merchant_id = merchant["id"]
            merchant_name = merchant.get("name", "Unknown")
            merchant_location = merchant.get("location", "").lower().strip()
            merchant_type = merchant.get("business_type", "").lower().strip()
            offer_desc = merchant.get("offer_description", "").strip()
            address = (merchant.get("address") or "").strip()

            print(f"\n🏪 Processing merchant: {merchant_name}")
            print(f"   Location: '{merchant_location}'")
            print(f"   Type: '{merchant_type}'")

            if not all([merchant_location, merchant_type, offer_desc]):
                print(f"   ⚠️  Skipping - missing location, type, or offer")
                continue

            eligible_customers = []
            for c in customers:
                customer_id = str(c["id"])

                # Check if already sent
                if (customer_id, str(merchant_id)) in sent_deal_logs:
                    continue

                # SCHEMA: zone is TEXT[], interests is TEXT[] (array)
                customer_zones_raw = c.get("zone") or []
                customer_interests_raw = c.get("interests") or []

                # The supabase-py library should automatically convert TEXT[] to a Python list,
                # but it appears to sometimes return a string representation of a list.
                if isinstance(customer_zones_raw, str):
                    try:
                        # The string can be a JSON-like array with unescaped newlines.
                        customer_zones_raw = json.loads(customer_zones_raw.replace('\n', ''))
                    except json.JSONDecodeError:
                        print(f"   ⚠️  Customer {customer_id} has malformed 'zone' data (could not parse string): {customer_zones_raw}")
                        customer_zones_raw = []
                if not isinstance(customer_zones_raw, list):
                    print(f"   ⚠️  Customer {customer_id} has malformed 'zone' data (expected list): {customer_zones_raw}")
                    customer_zones_raw = []

                customer_zones = [str(z).lower().strip() for z in customer_zones_raw if z]
                customer_interests = [str(i).lower().strip() for i in customer_interests_raw if i]

                # Check zone match (merchant location must be in customer's zones array)
                zone_match = merchant_location in customer_zones

                # Check interest match (merchant type must be in customer's interests array)
                interest_match = merchant_type in customer_interests

                if zone_match and interest_match:
                    eligible_customers.append(c)
                    print(
                        f"      ✓ Match: {c.get('phone_number')} (zones: {customer_zones}, interests: {customer_interests})"
                    )

            if not eligible_customers:
                print(f"   ℹ️  No new eligible customers")
                continue

            print(f"   ✅ Found {len(eligible_customers)} eligible customers")
            total_eligible_count += len(eligible_customers)

            for customer in eligible_customers:
                customer_id = str(customer["id"])
                short_code = generate_short_code()

                codes_to_log.append(
                    {
                        "customer_id": customer_id,
                        "merchant_id": str(merchant_id),
                        "code_string": short_code,
                        "status": "issued",
                    }
                )

                verification_url = f"{VERIFY_BASE_URL}?code={short_code}"
                qr_filename = f"{QR_CODE_DIR}/deal-{short_code}.png"
                create_qr_with_text(verification_url, short_code, qr_filename)
                qr_storage_path = f"deal-{short_code}.png"
                qr_code_url = ""

                try:
                    with open(qr_filename, "rb") as f:
                        supabase.storage.from_("qr_codes").upload(
                            path=qr_storage_path,
                            file=f,
                            file_options={"content-type": "image/png"},
                        )
                    qr_code_url = supabase.storage.from_("qr_codes").get_public_url(
                        qr_storage_path
                    )
                    print(f"      📤 Uploaded QR: {short_code}")
                except Exception as e:
                    print(f"      ❌ Error uploading QR code: {e}")
                    continue

                message_parts = [
                    f"Salut de la HaiAcolo! Ai o ofertă specială de la {merchant_name.upper()}:",
                    f"> {offer_desc}",
                ]
                if address:
                    maps_link = (
                        f"https://www.google.com/maps?q={urllib.parse.quote(address)}"
                    )
                    message_parts.append(f"Adresă: {maps_link}")
                message_parts.extend(
                    [
                        "Prezintă codul QR atașat la locație pentru a activa oferta.",
                        "Pentru a nu mai primi oferte, răspunde cu STOP.",
                    ]
                )
                message = "\n\n".join(message_parts)

                pending_sends_to_insert.append(
                    {
                        "phone_number": customer["phone_number"],
                        "message": message,
                        "qr_code_url": qr_code_url,
                        "short_code": short_code,
                        "customer_id": customer_id,
                        "merchant_id": str(merchant_id),
                    }
                )

        print("\n" + "=" * 60)
        print(f"📈 Matching Summary:")
        print(f"  - Total eligible matches: {total_eligible_count}")
        print(f"  - Deals to send: {len(pending_sends_to_insert)}")

        if pending_sends_to_insert:
            # --- Batch insert into database ---
            try:
                if codes_to_log:
                    print(f"\n💾 Inserting {len(codes_to_log)} codes into 'codes' table...")
                    for i in range(0, len(codes_to_log), BATCH_SIZE):
                        batch = codes_to_log[i:i + BATCH_SIZE]
                        supabase.table("codes").insert(batch).execute()
                        print(f"   ... inserted batch {i // BATCH_SIZE + 1}")
                    print("   ✅ All codes inserted successfully.")

                if pending_sends_to_insert:
                    print(f"\n💾 Inserting {len(pending_sends_to_insert)} deals into 'pending_sends' table...")
                    for i in range(0, len(pending_sends_to_insert), BATCH_SIZE):
                        batch = pending_sends_to_insert[i:i + BATCH_SIZE]
                        supabase.table("pending_sends").insert(batch).execute()
                        print(f"   ... inserted batch {i // BATCH_SIZE + 1}")
                    print("   ✅ All pending sends inserted successfully.")

                email_subject = f"{len(pending_sends_to_insert)} New HaiAcolo Deals are Ready to Send"
                email_body = (
                    f"The deal matching script has finished.\n\n"
                    f"{len(pending_sends_to_insert)} new deals have been added to the sending queue.\n\n"
                    f"Please visit the Sending Console to process them:\n"
                    f"{SENDER_CONSOLE_URL}"
                )
                send_notification_email(email_subject, email_body)

            except Exception as e:
                print(f"\n❌ FATAL: Error during batch inserts: {e}")
                import traceback

                traceback.print_exc()
                return
        else:
            print("\nℹ️  No new deals to send out this time.")

        print("\n✅ Deal matching process finished.")
        print("=" * 60)

    finally:
        if os.path.exists(QR_CODE_DIR):
            try:
                shutil.rmtree(QR_CODE_DIR)
                print(f"\n🧹 Cleaned up temporary directory: '{QR_CODE_DIR}'")
            except OSError as e:
                print(f"⚠️  Error cleaning up directory {QR_CODE_DIR}: {e}")


if __name__ == "__main__":
    run_deal_matching()
