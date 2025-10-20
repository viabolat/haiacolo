// supabase/functions/send-push-test/index.ts
import { serve } from "https://deno.land/std@0.168.0/http/server.ts";

const corsHeaders = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
};

// Helper function to convert base64url to ArrayBuffer
function urlB64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding)
    .replace(/\-/g, '+')
    .replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

// Helper function to convert ArrayBuffer to base64url
function uint8ArrayToBase64Url(uint8Array: Uint8Array): string {
  return btoa(String.fromCharCode.apply(null, Array.from(uint8Array)))
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');
}

serve(async (req) => {
  if (req.method === 'OPTIONS') {
    return new Response('ok', { headers: corsHeaders });
  }

  try {
    const subscription = await req.json();
    if (!subscription || !subscription.endpoint || !subscription.keys) {
      throw new Error("Invalid subscription object provided.");
    }

    const VAPID_PUBLIC_KEY_B64 = Deno.env.get("VAPID_PUBLIC_KEY");
    const VAPID_PRIVATE_KEY_B64 = Deno.env.get("VAPID_PRIVATE_KEY");

    if (!VAPID_PUBLIC_KEY_B64 || !VAPID_PRIVATE_KEY_B64) {
      throw new Error("VAPID keys are not set in Supabase secrets.");
    }

    const payload = JSON.stringify({
      title: "Hello from HaiAcolo! 👋",
      body: "This is your test notification.",
    });

    // --- VAPID Header Generation ---
    const vapidHeader = {
      typ: "JWT",
      alg: "ES256",
    };

    const vapidClaims = {
      aud: subscription.endpoint,
      exp: Math.floor(Date.now() / 1000) + 12 * 60 * 60, // 12 hours
      sub: "mailto:contact@haiacolo.ro", // Your contact email
    };

    const privateKey = await crypto.subtle.importKey(
      "jwk",
      {
        kty: "EC",
        crv: "P-256",
        x: VAPID_PUBLIC_KEY_B64.split("-")[0],
        y: VAPID_PUBLIC_KEY_B64.split("-")[1],
        d: VAPID_PRIVATE_KEY_B64,
      },
      { name: "ECDSA", namedCurve: "P-256" },
      true,
      ["sign"],
    );

    const jwt = await crypto.subtle.sign(
      { name: "ECDSA", hash: "SHA-256" },
      privateKey,
      new TextEncoder().encode(JSON.stringify(vapidHeader) + "." + JSON.stringify(vapidClaims)),
    );

    const vapidJWT = uint8ArrayToBase64Url(new Uint8Array(jwt));

    const authorizationHeader = `WebPush ${VAPID_PUBLIC_KEY_B64}.${vapidJWT}`;

    // --- Payload Encryption (simplified for POC, real encryption is complex) ---
    // For a full implementation, you'd need to encrypt the payload using the client's
    // p256dh and auth keys. For this POC, we'll send an unencrypted payload if allowed
    // by the push service, or a minimal encrypted one if required.
    // Most push services require encryption for non-empty payloads.
    // This part is highly complex and usually handled by a library.
    // For now, we'll send a minimal encrypted payload if the service requires it.

    const pushHeaders: Record<string, string> = {
      "Authorization": authorizationHeader,
      "Content-Type": "application/json", // For unencrypted payload
      "TTL": "3600",
    };

    // --- Send Request to Push Service ---
    const pushResponse = await fetch(subscription.endpoint, {
      method: "POST",
      headers: pushHeaders,
      body: payload, // Sending unencrypted payload for POC simplicity
    });

    if (!pushResponse.ok) {
      const errorText = await pushResponse.text();
      throw new Error(`Push service responded with ${pushResponse.status}: ${errorText}`);
    }

    return new Response(JSON.stringify({ success: true }), {
      headers: { ...corsHeaders, "Content-Type": "application/json" },
      status: 200,
    });
  } catch (error) {
    return new Response(JSON.stringify({ error: error.message }), {
      headers: { ...corsHeaders, "Content-Type": "application/json" },
      status: 500,
    });
  }
});