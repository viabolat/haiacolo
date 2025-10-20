import { serve } from "https://deno.land/std@0.168.0/http/server.ts";
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

// These secrets are stored securely in Supabase secrets and injected as environment variables
const APPROVAL_SECRET = Deno.env.get("APPROVAL_SECRET");
const SUPABASE_URL = Deno.env.get("SUPABASE_URL");
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");

serve(async (req) => {
  const url = new URL(req.url);
  const { searchParams } = url;

  // 1. Extract parameters from the URL
  const merchantId = searchParams.get("merchant_id");
  const action = searchParams.get("action"); // 'approve', 'reject', or 'approve_founder'
  const secret = searchParams.get("secret");

  // 2. Security Check: Validate the secret
  if (secret !== APPROVAL_SECRET) {
    return new Response("Unauthorized: Invalid secret", { status: 401 });
  }

  if (!merchantId || (action !== "approve" && action !== "reject" && action !== "approve_founder")) {
    return new Response("Missing or invalid parameters", { status: 400 });
  }

  try {
    // 3. Create a Supabase client with the service_role key to bypass RLS
    const supabaseAdmin = createClient(SUPABASE_URL!, SUPABASE_SERVICE_ROLE_KEY!);

    // 4. Determine the update payload based on the action
    let updatePayload: object;
    let statusMessage: string;

    if (action === 'approve_founder') {
        updatePayload = { subscription_status: 'active', is_founding_member: true };
        statusMessage = 'approved as a Founding Member';
    } else {
        const newStatus = action === "approve" ? "active" : "rejected";
        updatePayload = { subscription_status: newStatus };
        statusMessage = newStatus;
    }

    // 5. Update the merchant's record in the database
    const { data, error } = await supabaseAdmin
      .from("merchants")
      .update(updatePayload)
      .eq("id", merchantId)
      .select()
      .single();

    if (error) {
      console.error("Supabase error:", error);
      throw new Error(error.message);
    }

    // 6. Return a user-friendly confirmation page
    const confirmationHtml = `
      <html>
        <body style="font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f4f4f4;">
          <div style="background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); text-align: center;">
            <h1 style="color: ${action.includes('approve') ? '#28a745' : '#dc3545'};">Action Complete</h1>
            <p>The merchant <strong>${data.name}</strong> has been successfully <strong>${statusMessage}</strong>.</p>
            <p style="font-size: 12px; color: #888;">You can now close this window.</p>
          </div>
        </body>
      </html>
    `;

    return new Response(confirmationHtml, { headers: { "Content-Type": "text/html" } });

  } catch (error) {
    console.error("Error processing request:", error);
    return new Response(`Internal Server Error: ${error.message}`, { status: 500 });
  }
});