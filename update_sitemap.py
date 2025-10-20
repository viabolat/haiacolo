import os
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path
import logging
import argparse

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# --- Configuration ---

# The path to your sitemap file
SITEMAP_FILE = os.environ.get("SITEMAP_FILE", "sitemap.xml")

# The base URL of your website
BASE_URL = os.environ.get("BASE_URL", "https://haiacolo.ro/")

def get_html_files(base_path: str = ".") -> Dict[str, str]:
    """
    Dynamically discovers HTML files and creates a URL-to-file mapping.
    Assumes URLs are derived directly from file paths relative to BASE_URL.
    """
    html_map = {}
    for root, _, files in os.walk(base_path):
        for file in files:
            if file.endswith(".html"):
                relative_path = Path(root) / file
                # Construct URL: replace .html with empty string for root, otherwise use filename
                if file == "index.html":
                    url_path = ""
                else:
                    url_path = file.replace(".html", "")
                html_map[f"{BASE_URL}{url_path}"] = str(relative_path)
    return html_map


def update_sitemap_lastmod(dry_run: bool = False):
    """
    Updates the <lastmod> tags in a sitemap.xml file based on the
    actual file modification times and dynamically discovered HTML files.
    If dry_run is True, it only reports what would be changed without performing the write.
    """
    logger.info(f"--- Processing sitemap: {SITEMAP_FILE}")
    if dry_run:
        logger.info("--- DRY RUN: No actual changes will be written to sitemap.xml. ---")

    try:
        # Register the namespace to correctly parse and write the XML
        ET.register_namespace("", "http://www.sitemaps.org/schemas/sitemap/0.9")

        tree = ET.parse(SITEMAP_FILE)
        root = tree.getroot()
        ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}

        # Dynamically get the mapping of URLs to local files
        url_to_file_map = get_html_files()
        
        urls_processed = 0
        urls_in_sitemap = set()
        changes_made = False

        # First pass: Update existing URLs and track them
        for url_element in root.findall("sm:url", ns):
            loc_element = url_element.find("sm:loc", ns)
            if loc_element is None or not loc_element.text:
                logger.warning("Skipping malformed <url> entry (missing or empty <loc>).")
                continue

            url = loc_element.text.strip()
            urls_in_sitemap.add(url)

            if url in url_to_file_map:
                filepath = url_to_file_map[url]
                if os.path.exists(filepath):
                    mod_time_unix = os.path.getmtime(filepath)
                    mod_time_dt = datetime.fromtimestamp(mod_time_unix, tz=timezone.utc)
                    w3c_format_date = mod_time_dt.isoformat()

                    lastmod_element = url_element.find("sm:lastmod", ns)
                    if lastmod_element is None:
                        lastmod_element = ET.SubElement(url_element, "lastmod")
                        lastmod_element.text = w3c_format_date
                        logger.info(f"  -> Added <lastmod> for {url} to {w3c_format_date}")
                        changes_made = True
                    elif lastmod_element.text != w3c_format_date:
                        lastmod_element.text = w3c_format_date
                        logger.info(f"  -> Updated {url} to {w3c_format_date}")
                        changes_made = True
                    urls_processed += 1
                else:
                    logger.warning(f"  -> Warning: File not found for URL {url} at {filepath}. Skipping update.")
            else:
                logger.warning(f"  -> Warning: URL {url} found in sitemap but not in dynamic file map. Skipping update.")

        # Second pass: Add new URLs that are not yet in the sitemap
        for url, filepath in url_to_file_map.items():
            if url not in urls_in_sitemap:
                if os.path.exists(filepath):
                    mod_time_unix = os.path.getmtime(filepath)
                    mod_time_dt = datetime.fromtimestamp(mod_time_unix, tz=timezone.utc)
                    w3c_format_date = mod_time_dt.isoformat()

                    url_element = ET.SubElement(root, "url")
                    loc_element = ET.SubElement(url_element, "loc")
                    loc_element.text = url
                    lastmod_element = ET.SubElement(url_element, "lastmod")
                    lastmod_element.text = w3c_format_date
                    logger.info(f"  -> Added new URL {url} with lastmod {w3c_format_date}")
                    changes_made = True
                    urls_processed += 1
                else:
                    logger.warning(f"  -> Warning: Dynamically discovered file {filepath} for URL {url} not found. Skipping addition.")


        if changes_made and not dry_run:
            # Write the changes back to the sitemap file
            tree.write(SITEMAP_FILE, encoding="UTF-8", xml_declaration=True)
            logger.info(f"\nSitemap update complete. {urls_processed} URLs processed/added.")
        elif changes_made and dry_run:
            logger.info(f"\nDry run complete. {urls_processed} URLs would have been processed/added.")
        else:
            logger.info("\nNo changes needed for sitemap.xml.")


    except (FileNotFoundError, ET.ParseError) as e:
        logger.error(f"Error: Could not read or parse {SITEMAP_FILE}. Details: {e}")
    except Exception as e:
        logger.error(f"An unexpected error occurred: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Update sitemap.xml with last modification dates.")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="If set, no actual changes will be written to sitemap.xml, only a report of what would be changed."
    )
    args = parser.parse_args()
    update_sitemap_lastmod(dry_run=args.dry_run)
