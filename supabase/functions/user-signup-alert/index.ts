import { serve } from "std/http/server.ts";

// Secrets are stored in Supabase
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY");
const NOTIFICATION_EMAIL = "new_user@haiacolo.ro";

interface Customer {
  id: string;
  full_name?: string;
  phone_number: string;
  zone: string[] | string; // Accept both array and string
  interests: string[] | null;
  email?: string; // Add email field
}

serve(async (req) => {
  console.log("🚀 User Signup Alert triggered!");
  console.log("Method:", req.method);
  
  if (req.method !== "POST") {
    return new Response("Method Not Allowed", { status: 405 });
  }

  try {
    const payload = await req.json();
    console.log("📦 Received payload:", JSON.stringify(payload, null, 2));
    
    const customer: Customer = payload.record;

    if (!customer || !customer.id) {
      console.error("Invalid payload received:", payload);
      return new Response("Invalid payload", { status: 400 });
    }

    // Handle interests array
    const interestsDisplay = (customer.interests && Array.isArray(customer.interests) && customer.interests.length > 0)
      ? customer.interests.join(', ')
      : 'None';

    // Handle zone - can be string or array
    const zoneDisplay = Array.isArray(customer.zone)
      ? customer.zone.join(', ')
      : customer.zone || 'Not specified';

    // Create the email content
    const emailHtml = `
      <html>
        <body style="font-family: sans-serif; padding: 20px;">
          <h1 style="color: #333;">🎉 New User Subscription</h1>
          <p>A new user has subscribed on HaiAcolo.</p>
          <hr>
          <h3 style="color: #555;">Subscriber Details:</h3>
          <ul>
            <li><strong>Name:</strong> ${customer.full_name || 'Not provided'}</li>
            <li><strong>Phone:</strong> ${customer.phone_number}</li>
            <li><strong>Email:</strong> ${customer.email || 'Not provided'}</li>
            <li><strong>Zones:</strong> ${zoneDisplay}</li>
            <li><strong>Interests:</strong> ${interestsDisplay}</li>
          </ul>
          <hr>
          <p style="font-size: 12px; color: #888;">
            Customer ID: ${customer.id}
          </p>
        </body>
      </html>
    `;

    console.log("📧 Attempting to send email via Resend...");
    console.log("Sending to:", NOTIFICATION_EMAIL);
    console.log("API Key present:", !!RESEND_API_KEY);

    // Send the email via Resend
    const resendResponse = await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${RESEND_API_KEY}`,
      },
      body: JSON.stringify({
        from: `HaiAcolo Alerts <alerts@haiacolo.ro>`,
        to: NOTIFICATION_EMAIL,
        subject: `New User Signup: ${customer.phone_number}`,
        html: emailHtml,
      }),
    });

    const responseBody = await resendResponse.text();
    console.log("📬 Resend API response status:", resendResponse.status);
    console.log("📬 Resend API response body:", responseBody);

    if (!resendResponse.ok) {
      console.error(`❌ Failed to send email: ${resendResponse.status}`, responseBody);
      return new Response(`Failed to send email: ${responseBody}`, { status: 500 });
    }

    console.log(`✅ Notification email sent for new user: ${customer.phone_number}`);
    return new Response(JSON.stringify({ message: "Notification sent" }), {
      headers: { "Content-Type": "application/json" },
      status: 200,
    });

  } catch (error) {
    console.error("❌ Error processing request:", error);
    return new Response(`Internal Server Error: ${error.message}`, { status: 500 });
  }
});