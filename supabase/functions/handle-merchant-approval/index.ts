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

// These secrets are stored securely in Supabase secrets
const SUPABASE_URL = Deno.env.get("SUPABASE_URL");
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY");

const ERROR_HTML = `
  <html><body style="font-family: sans-serif; text-align: center; padding: 40px;">
    <h1 style="color: #dc3545;">Link Invalid or Expired</h1>
    <p>This approval link is either invalid or has expired. Please generate a new one if needed.</p>
  </body></html>
`;

// Inline email template function
const getMerchantApprovalEmailTemplate = (businessName: string, offerDescription: string): string => {
  return `
    <!DOCTYPE html>
    <html lang="ro">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Parteneriatul tău a fost aprobat! | HaiAcolo</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                color: #333;
                line-height: 1.6;
            }
            .container {
                max-width: 600px;
                margin: 20px auto;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
            }
            .header {
                text-align: center;
                padding-bottom: 20px;
                border-bottom: 1px solid #eee;
            }
            .header img {
                max-width: 150px;
            }
            .content {
                padding: 20px 0;
            }
            .offer-box {
                background-color: #f0f9ff;
                border-left: 4px solid #0ea5e9;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .footer {
                text-align: center;
                font-size: 12px;
                color: #888;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <img src="https://haiacolo.ro/public/logo.png" alt="HaiAcolo Logo">
                <h1>Felicitări, ${businessName}!</h1>
            </div>
            <div class="content">
                <p>Salut,</p>
                <p>Avem vești excelente! Aplicația ta a fost aprobată și ești oficial partener HaiAcolo. Bun venit în
                    comunitate!</p>
                <p>Vom începe să promovăm oferta ta către abonații noștri interesați:</p>
                <div class="offer-box">
                    <p><strong>Oferta ta activă:</strong> "${offerDescription}"</p>
                </div>
                <p>Îți reamintim că parteneriatul nostru este bazat pe performanță. Asta înseamnă că vei plăti un comision
                    doar atunci când un client HaiAcolo scanează un cod de ofertă la locația ta, generând o vânzare directă.
                </p>
                <p>Îți mulțumim pentru încredere și abia așteptăm să contribuim la succesul afacerii tale!</p>
            </div>
            <div class="footer">
                <p>&copy; 2025 HaiAcolo. Toate drepturile rezervate.</p>
                <p style="margin-top: 10px;">Te rugăm să nu răspunzi la acest email. Adresa nu este monitorizată. Pentru
                    orice întrebări, te rugăm să ne contactezi la <a href="mailto:contact@haiacolo.ro"
                        style="color: #007bff;">contact@haiacolo.ro</a>.</p>
            </div>
        </div>
    </body>
    </html>
  `;
};

/**
 * Sends an approval notification email to the merchant.
 * @param merchant The merchant object containing contact details.
 */
async function sendApprovalEmail(merchant: any) {
  if (!RESEND_API_KEY) {
    console.error("❌ RESEND_API_KEY is not set. Cannot send approval email.");
    return;
  }

  try {
    const emailHtml = getMerchantApprovalEmailTemplate(merchant.name, merchant.offer_description);

    const resendResponse = await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${RESEND_API_KEY}`,
      },
      body: JSON.stringify({
        from: `HaiAcolo <noreply@haiacolo.ro>`,
        to: merchant.contact_email,
        subject: `Felicitări! Parteneriatul tău HaiAcolo a fost aprobat!`,
        html: emailHtml,
      }),
    });

    console.log(`📬 Approval email to ${merchant.contact_email} - Resend status: ${resendResponse.status}`);
  } catch (error) {
    console.error("❌ Error sending approval email:", error);
  }
}

serve(async (req) => {
  console.log("🔐 Handle Merchant Approval triggered!");
  console.log("Method:", req.method);
  console.log("URL:", req.url);
  
  const url = new URL(req.url);
  const { searchParams } = url;

  // 1. Extract parameters from the URL
  const merchantId = searchParams.get("merchant_id");
  const action = searchParams.get("action");
  const token = searchParams.get("token");

  console.log("Parameters:", { merchantId, action, tokenPresent: !!token });

  if (!merchantId || !action || !token || (action !== "approve" && action !== "reject" && action !== "approve_founder")) {
    console.error("❌ Missing or invalid parameters");
    return new Response("Missing or invalid parameters", { status: 400 });
  }

  try {
    console.log("🔑 Environment check:");
    console.log("- SUPABASE_URL:", !!SUPABASE_URL);
    console.log("- SUPABASE_SERVICE_ROLE_KEY:", !!SUPABASE_SERVICE_ROLE_KEY);
    console.log("- RESEND_API_KEY:", !!RESEND_API_KEY);

    if (!SUPABASE_URL || !SUPABASE_SERVICE_ROLE_KEY) {
      console.error("❌ Missing environment variables");
      return new Response("Server configuration error", { status: 500 });
    }

    // Create Supabase client with service role key
    const supabaseAdmin = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, {
      auth: {
        autoRefreshToken: false,
        persistSession: false
      }
    });

    const tokenHash = await sha256(token);
    console.log("🔍 Looking up merchant with ID:", merchantId);

    // 2. Find the merchant by the token hash
    const { data: merchant, error: findError } = await supabaseAdmin
      .from('merchants')
      .select('*')
      .eq('id', merchantId)
      .eq('approval_token_hash', tokenHash)
      .single();

    if (findError) {
      console.error("❌ Error finding merchant:", findError);
      return new Response(ERROR_HTML, { status: 401, headers: { "Content-Type": "text/html" } });
    }

    if (!merchant) {
      console.error("❌ No merchant found with matching token");
      return new Response(ERROR_HTML, { status: 401, headers: { "Content-Type": "text/html" } });
    }

    console.log("✅ Merchant found:", merchant.name);

    // 3. Check if the token is expired
    const tokenExpiresAt = new Date(merchant.approval_token_expires_at);
    const now = new Date();
    
    console.log("Token expires at:", tokenExpiresAt);
    console.log("Current time:", now);

    if (tokenExpiresAt < now) {
      console.error("❌ Token expired");
      return new Response(ERROR_HTML, { status: 401, headers: { "Content-Type": "text/html" } });
    }

    console.log("✅ Token is valid");

    // 4. Determine the update payload based on the action
    let updatePayload: any;
    let statusMessage: string;

    if (action === 'approve_founder') {
        updatePayload = { 
            subscription_status: 'active', 
            is_founding_member: true,
            approval_token_hash: null,
            approval_token_expires_at: null
        };
        statusMessage = 'approved as a Founding Member';
        console.log("⭐ Approving as founding member");
    } else if (action === 'approve') {
        updatePayload = { 
            subscription_status: 'active',
            approval_token_hash: null,
            approval_token_expires_at: null
        };
        statusMessage = 'approved';
        console.log("✅ Approving merchant");
    } else {
        updatePayload = { 
            subscription_status: 'rejected',
            approval_token_hash: null,
            approval_token_expires_at: null
        };
        statusMessage = 'rejected';
        console.log("❌ Rejecting merchant");
    }

    // 5. Update the merchant's record in the database
    const { data: updatedMerchant, error: updateError } = await supabaseAdmin
      .from("merchants")
      .update(updatePayload)
      .eq("id", merchantId)
      .select()
      .single();

    if (updateError) {
      console.error("❌ Supabase error during update:", updateError);
      throw new Error(updateError.message);
    }

    console.log("✅ Merchant updated successfully:", updatedMerchant.name);

    // 6. Send approval email to the merchant if the action was an approval
    if (action.includes('approve')) {
      await sendApprovalEmail(updatedMerchant);
    }

    // 7. Return a user-friendly confirmation page
    const confirmationHtml = `
      <html>
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Action Complete - HaiAcolo</title>
        </head>
        <body style="font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f4f4; margin: 0; padding: 20px;">
          <div style="background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); text-align: center; max-width: 500px;">
            <h1 style="color: ${action.includes('approve') ? '#28a745' : '#dc3545'}; margin-top: 0;">
              ${action.includes('approve') ? '✅ Approved' : '❌ Rejected'}
            </h1>
            <p style="font-size: 18px; color: #333;">
              The merchant <strong>${updatedMerchant.name}</strong> has been successfully <strong>${statusMessage}</strong>.
            </p>
            ${action === 'approve_founder' ? '<p style="color: #ffc107; font-weight: bold; font-size: 20px;">⭐ Granted Founding Member Status</p>' : ''}
            <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
            <p style="font-size: 14px; color: #888;">
              This approval link has been invalidated and cannot be used again.
            </p>
            <p style="font-size: 12px; color: #aaa; margin-top: 20px;">
              You can now close this window.
            </p>
          </div>
        </body>
      </html>
    `;

    return new Response(confirmationHtml, { 
      status: 200,
      headers: { "Content-Type": "text/html; charset=utf-8" } 
    });

  } catch (error: any) {
    console.error("❌ Error processing request:", error);
    const errorHtml = `
      <html><body style="font-family: sans-serif; text-align: center; padding: 40px;">
        <h1 style="color: #dc3545;">Error Processing Request</h1>
        <p>An error occurred: ${error.message}</p>
        <p style="font-size: 12px; color: #888;">Please contact support if this continues.</p>
      </body></html>
    `;
    return new Response(errorHtml, { 
      status: 500, 
      headers: { "Content-Type": "text/html" } 
    });
  }
});