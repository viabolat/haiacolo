import { serve } from "https://deno.land/std@0.168.0/http/server.ts";

// Secrets are stored in Supabase
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY");
const NOTIFICATION_EMAIL = "new_user@haiacolo.ro";

interface Customer {
  id: string;
  full_name?: string;
  phone_number: string;
  zone: string;
  interests: string | string[]; // Allow interests to be string or string[]
}

serve(async (req) => {
  if (req.method !== "POST") {
    return new Response("Method Not Allowed", { status: 405 });
  }

  try {
    const payload = await req.json();
    const customer: Customer = payload.record;

    if (!customer || !customer.id) {
      console.error("Invalid payload received:", payload);
      return new Response("Invalid payload", { status: 400 });
    }

    let interestsDisplay = 'None';
    if (customer.interests) {
      try {
        // Attempt to parse as JSON array
        const parsedInterests = JSON.parse(customer.interests as string);
        if (Array.isArray(parsedInterests)) {
          interestsDisplay = parsedInterests.join(', ');
        } else {
          // If not an array after parsing, or not JSON, just use as is
          interestsDisplay = customer.interests.toString();
        }
      } catch (e) {
        // If JSON parsing fails, treat it as a plain string
        interestsDisplay = customer.interests.toString();
      }
    }

    // Create the email content
    const emailHtml = `
      <html>
        <body style="font-family: sans-serif; padding: 20px;">
          <h1 style="color: #333;">New User Subscription</h1>
          <p>A new user has subscribed on HaiAcolo.</p>
          <hr>
          <h3 style="color: #555;">Subscriber Details:</h3>
          <ul>
            <li><strong>Name:</strong> ${customer.full_name || 'Not provided'}</li>
            <li><strong>Phone:</strong> ${customer.phone_number}</li>
            <li><strong>Zone:</strong> ${customer.zone}</li>
            <li><strong>Interests:</strong> ${interestsDisplay}</li>
          </ul>
        </body>
      </html>
    `;

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

    if (!resendResponse.ok) {
      const errorBody = await resendResponse.text();
      console.error(`Failed to send email: ${resendResponse.status}`, errorBody);
      return new Response(`Failed to send email: ${errorBody}`, { status: 500 });
    }

    console.log(`Notification email sent for new user: ${customer.phone_number}`);
    return new Response(JSON.stringify({ message: "Notification sent" }), {
      headers: { "Content-Type": "application/json" },
      status: 200,
    });

  } catch (error) {
    console.error("Error processing request:", error);
    return new Response("Internal Server Error", { status: 500 });
  }
});