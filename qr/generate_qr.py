"""
generate_qr.py
Generates printable QR code cards for hotel guest room testing (Room 101 & Room 102).
Each QR code encodes the URL with the ?room= parameter and adds a human-readable room label.
"""

import os
import socket
import qrcode
from PIL import Image, ImageDraw, ImageFont

def get_local_ip():
    """Auto-detect the laptop's active local IPv4 address on Wi-Fi/LAN."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return "192.168.254.105" # Fallback IPv4 if offline; replace with your laptop IPv4

# -------------------------------------------------------------------------
# Server Configuration (Must match your actual web server host/port/path!)
#   * XAMPP Apache (recommended): PORT = 80,   BASE_PATH = "/smartscent"
#   * PHP CLI server debug:       PORT = 8000, BASE_PATH = ""
# -------------------------------------------------------------------------
PORT = 80
BASE_PATH = "/smartscent"
SERVER_IP = get_local_ip()
PORT_PART = "" if PORT == 80 else f":{PORT}"
BASE_URL = f"http://{SERVER_IP}{PORT_PART}{BASE_PATH}/login.php?room="
ROOMS = ["101", "102"]

def create_room_qr(room_number, output_filename):
    url = f"{BASE_URL}{room_number}"
    print(f"Generating QR for Room {room_number} -> {url}")

    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=12,
        border=3,
    )
    qr.add_data(url)
    qr.make(fit=True)

    qr_image = qr.make_image(fill_color="#102822", back_color="#ffffff").convert("RGB")
    qr_width, qr_height = qr_image.size

    label_height = 80
    border_padding = 24
    canvas_width = qr_width + (border_padding * 2)
    canvas_height = qr_height + label_height + (border_padding * 2)

    canvas = Image.new("RGB", (canvas_width, canvas_height), "#ffffff")
    draw = ImageDraw.Draw(canvas)
    canvas.paste(qr_image, (border_padding, border_padding))

    text = f"Room {room_number}"
    subtext = "SmartScent Guest Access"

    try:
        font_large = ImageFont.truetype("arial.ttf", 28)
        font_small = ImageFont.truetype("arial.ttf", 16)
    except IOError:
        font_large = ImageFont.load_default()
        font_small = ImageFont.load_default()

    try:
        bbox_large = draw.textbbox((0, 0), text, font=font_large)
        text_w = bbox_large[2] - bbox_large[0]
        bbox_small = draw.textbbox((0, 0), subtext, font=font_small)
        sub_w = bbox_small[2] - bbox_small[0]
    except AttributeError:
        text_w, _ = draw.textsize(text, font=font_large)
        sub_w, _ = draw.textsize(subtext, font=font_small)

    text_x = (canvas_width - text_w) // 2
    text_y = qr_height + border_padding + 10
    draw.text((text_x, text_y), text, fill="#102822", font=font_large)

    sub_x = (canvas_width - sub_w) // 2
    sub_y = text_y + 34
    draw.text((sub_x, sub_y), subtext, fill="#687973", font=font_small)

    draw.rectangle(
        [(2, 2), (canvas_width - 3, canvas_height - 3)],
        outline="#dce5e1",
        width=2
    )

    canvas.save(output_filename, dpi=(300, 300))
    print(f"Saved: {output_filename} ({canvas_width}x{canvas_height} px)")

def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    print(f"Active Laptop IP: {SERVER_IP}")
    print(f"Base QR URL: {BASE_URL}")
    for room in ROOMS:
        out_file = os.path.join(script_dir, f"qr_room_{room}.png")
        create_room_qr(room, out_file)
    print("\nAll QR codes generated successfully!")

if __name__ == "__main__":
    main()