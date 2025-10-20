import { serve } from "https://deno.land/std@0.168.0/http/server.ts";
import { createClient } from "https://esm.sh/@supabase/supabase-js@2.43.4";

// These secrets are stored securely in Supabase secrets and injected as environment variables
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY");
const APPROVAL_SECRET = Deno.env.get("APPROVAL_SECRET");
const NOTIFICATION_EMAIL = "new_business@haiacolo.ro";
const SUPABASE_URL = Deno.env.get("SUPABASE_URL");
const SUPABASE_ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY");

// The base URL for our approval function
const FUNCTION_BASE_URL = `${SUPABASE_URL}/functions/v1/handle-merchant-approval`;

interface Merchant {
  id: string;
  name: string;
  location: string;
  business_type: string;
  owner_contact: string; // Changed from contact_person
  contact_email: string;
  contact_phone: string;
  offer_description: string;
}

serve(async (req) => {
  // This function is triggered by a Supabase database webhook
  // It expects a POST request with the new merchant record
  if (req.method !== "POST") {
    return new Response("Method Not Allowed", { status: 405 });
  }

  try {
    const payload = await req.json();
    const merchant: Merchant = payload.record;

    if (!merchant || !merchant.id) {
      console.error("Invalid payload received:", payload);
      return new Response("Invalid payload", { status: 400 });
    }

    // Create a Supabase client
    const supabase = createClient(SUPABASE_URL!, SUPABASE_ANON_KEY!);

    // 1. Count existing founding members
    const { count, error: countError } = await supabase
      .from('merchants')
      .select('*', { count: 'exact', head: true })
      .eq('is_founding_member', true);

    if (countError) {
      console.error("Error counting founding members:", countError);
      // Don't block the email if this fails, just log it
    }

    // 2. Construct the secure approval and rejection links
    const approveUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=approve&secret=${APPROVAL_SECRET}`;
    const rejectUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=reject&secret=${APPROVAL_SECRET}`;
    let approveAsFounderUrl = '';

    if (count !== null && count < 10) {
        approveAsFounderUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=approve_founder&secret=${APPROVAL_SECRET}`;
    }

    // 3. Create the email content
    const emailHtml = `
      <html>
        <body style="font-family: sans-serif; padding: 20px;">
          <h1 style="color: #333;">New Merchant Application</h1>
          <p>A new merchant has applied on HaiAcolo. Please review their details below.</p>
          <hr>
          <h3 style="color: #555;">Merchant Details:</h3>
          <ul>
            <li><strong>Name:</strong> ${merchant.name}</li>
            <li><strong>Location:</strong> ${merchant.location}</li>
            <li><strong>Type:</strong> ${merchant.business_type}</li>
            <li><strong>Contact Name:</strong> ${merchant.owner_contact}</li>
            <li><strong>Contact Email:</strong> ${merchant.contact_email}</li>
            <li><strong>Contact Phone:</strong> ${merchant.contact_phone}</li>
          </ul>
          <h3 style="color: #555;">Proposed Offer:</h3>
          <p style="background-color: #f4f4f4; padding: 10px; border-radius: 5px;">
            ${merchant.offer_description}
          </p>
          <hr>
          <h3 style="color: #555;">Actions:</h3>
          <p>
            <a href="${approveUrl}" style="background-color: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;">Approve</a>
            ${approveAsFounderUrl ? `<a href="${approveAsFounderUrl}" style="background-color: #ffc107; color: black; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;">Approve as Founder</a>` : ''}
            <a href="${rejectUrl}" style="background-color: #dc3545; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;">Reject</a>
          </p>
          <p style="font-size: 12px; color: #888;">
            Approving will set the merchant's subscription status to 'active'. Approving as a founder will also grant them special status.
          </p>
        </body>
      </html>
    `;

    // 4. Send the email via Resend
    const resendResponse = await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${RESEND_API_KEY}`,
      },
      body: JSON.stringify({
        from: `HaiAcolo Alerts <alerts@haiacolo.ro>`,
        to: NOTIFICATION_EMAIL,
        subject: `New Merchant Application: ${merchant.name}`,
        html: emailHtml,
      }),
    });

    if (!resendResponse.ok) {
      const errorBody = await resendResponse.text();
      console.error(`Failed to send email: ${resendResponse.status}`, errorBody);
      return new Response(`Failed to send email: ${errorBody}`, { status: 500 });
    }

    console.log(`Notification email sent for merchant: ${merchant.name}`);
    return new Response(JSON.stringify({ message: "Notification sent" }), {
      headers: { "Content-Type": "application/json" },
      status: 200,
    });

  } catch (error) {
    console.error("Error processing request:", error);
    return new Response("Internal Server Error", { status: 500 });
  }
});