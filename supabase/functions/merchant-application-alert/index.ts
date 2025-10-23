import { serve } from "std/http/server.ts";
import { createClient } from "@supabase/supabase-js";

// Helper to hash the token
async function sha256(text: string) {
  const encoder = new TextEncoder();
  const data = encoder.encode(text);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
  return hashHex;
}

// Generate a simple UUID v4
function generateUUID(): string {
  return crypto.randomUUID();
}

// These secrets are stored securely in Supabase secrets and injected as environment variables
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY");
const NOTIFICATION_EMAIL = "new_business@haiacolo.ro";
const SUPABASE_URL = Deno.env.get("SUPABASE_URL");
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");

// The base URL for our approval function
const FUNCTION_BASE_URL = `${SUPABASE_URL}/functions/v1/handle-merchant-approval`;

interface Merchant {
  id: string;
  name: string;
  location: string;
  business_type: string;
  owner_contact: string;
  contact_email: string;
  contact_phone: string;
  offer_description: string;
  business_description?: string;
  address?: string;
}

serve(async (req) => {
  console.log("🚀 Merchant Application Alert triggered!");
  console.log("Method:", req.method);
  
  if (req.method !== "POST") {
    return new Response("Method Not Allowed", { status: 405 });
  }

  try {
    const payload = await req.json();
    console.log("📦 Received payload:", JSON.stringify(payload, null, 2));
    
    const merchant: Merchant = payload.record;

    if (!merchant || !merchant.id) {
      console.error("❌ Invalid payload received:", payload);
      return new Response("Invalid payload", { status: 400 });
    }

    console.log("🔑 Environment check:");
    console.log("- SUPABASE_URL:", !!SUPABASE_URL);
    console.log("- SUPABASE_SERVICE_ROLE_KEY:", !!SUPABASE_SERVICE_ROLE_KEY);
    console.log("- RESEND_API_KEY:", !!RESEND_API_KEY);

    // Use the service role key to update the merchant record
    const supabaseAdmin = createClient(SUPABASE_URL!, SUPABASE_SERVICE_ROLE_KEY!);

    // 1. Generate a secure, single-use approval token
    const approvalToken = generateUUID();
    const approvalTokenHash = await sha256(approvalToken);
    const tokenExpiry = new Date(Date.now() + 72 * 60 * 60 * 1000); // 72 hours from now

    console.log("🔐 Generated approval token (first 8 chars):", approvalToken.substring(0, 8));

    // 2. Store the hashed token and expiry in the merchants table
    const { error: updateError } = await supabaseAdmin
      .from('merchants')
      .update({
        approval_token_hash: approvalTokenHash,
        approval_token_expires_at: tokenExpiry.toISOString(),
      })
      .eq('id', merchant.id);

    if (updateError) {
      console.error("❌ Error updating merchant with token:", updateError);
      throw new Error("Failed to set up approval token for merchant.");
    }

    console.log("✅ Token saved to database");

    // 3. Count existing founding members
    const { count, error: countError } = await supabaseAdmin
      .from('merchants')
      .select('*', { count: 'exact', head: true })
      .eq('is_founding_member', true);

    if (countError) {
      console.error("⚠️ Error counting founding members:", countError);
    } else {
      console.log("👥 Current founding members:", count);
    }

    // 4. Construct the secure approval and rejection links with the RAW token
    const approveUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=approve&token=${approvalToken}`;
    const rejectUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=reject&token=${approvalToken}`;
    let approveAsFounderUrl = '';

    if (count !== null && count < 10) {
      approveAsFounderUrl = `${FUNCTION_BASE_URL}?merchant_id=${merchant.id}&action=approve_founder&token=${approvalToken}`;
    }

    // 5. Create the email content
    const emailHtml = `
      <html>
        <body style="font-family: sans-serif; padding: 20px;">
          <h1 style="color: #333;">🏪 New Merchant Application</h1>
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
            ${merchant.address ? `<li><strong>Address:</strong> ${merchant.address}</li>` : ''}
          </ul>
          ${merchant.business_description ? `
          <h3 style="color: #555;">Business Description:</h3>
          <p style="background-color: #f9f9f9; padding: 10px; border-radius: 5px;">
            ${merchant.business_description}
          </p>
          ` : ''}
          <h3 style="color: #555;">Proposed Offer:</h3>
          <p style="background-color: #f4f4f4; padding: 10px; border-radius: 5px;">
            ${merchant.offer_description || 'No offer description provided'}
          </p>
          <hr>
          <h3 style="color: #555;">Actions:</h3>
          <p>
            <a href="${approveUrl}" style="background-color: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px; display: inline-block; margin-bottom: 10px;">✅ Approve</a>
            ${approveAsFounderUrl ? `<a href="${approveAsFounderUrl}" style="background-color: #ffc107; color: black; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px; display: inline-block; margin-bottom: 10px;">⭐ Approve as Founder</a>` : ''}
            <a href="${rejectUrl}" style="background-color: #dc3545; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; display: inline-block; margin-bottom: 10px;">❌ Reject</a>
          </p>
          <p style="font-size: 12px; color: #888;">
            Approving will set the merchant's subscription status to 'active'. 
            ${count !== null && count < 10 ? 'Approving as a founder will also grant them special founding member status.' : ''}
          </p>
          <hr>
          <p style="font-size: 11px; color: #aaa;">
            Merchant ID: ${merchant.id}<br>
            Token expires: ${tokenExpiry.toLocaleString()}
          </p>
        </body>
      </html>
    `;

    console.log("📧 Sending email to:", NOTIFICATION_EMAIL);

    // 6. Send the email via Resend
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

    const responseBody = await resendResponse.text();
    console.log("📬 Resend API response status:", resendResponse.status);
    console.log("📬 Resend API response body:", responseBody);

    if (!resendResponse.ok) {
      console.error(`❌ Failed to send email: ${resendResponse.status}`, responseBody);
      return new Response(`Failed to send email: ${responseBody}`, { status: 500 });
    }

    console.log(`✅ Notification email sent for merchant: ${merchant.name}`);
    return new Response(JSON.stringify({ message: "Notification sent" }), {
      headers: { "Content-Type": "application/json" },
      status: 200,
    });

  } catch (error) {
    console.error("❌ Error processing request:", error);
    return new Response(`Internal Server Error: ${error.message}`, { status: 500 });
  }
});