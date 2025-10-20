// 1. Centralized DOM element selection
const elements = {};

// 2. Centralized state management
const appState = {
  currentCodeId: null,
  currentCodeString: null,
  currentOffer: null,
  ui: "initial", // 'initial', 'loading', 'valid', 'scanning', 'manual_entry', 'result'
  scanner: null,
};

// 3. Setup event listeners and initialize the app
document.addEventListener("DOMContentLoaded", () => {
  // Cache all DOM elements at the start
  elements.card = document.getElementById("verification-card");
  elements.initialState = document.getElementById("initial-state");
  elements.loadingIndicator = document.getElementById("loading-indicator");
  elements.resultDisplay = document.getElementById("result-display");
  elements.resultTitle = document.getElementById("result-title");
  elements.resultMessage = document.getElementById("result-message");
  elements.offerWrapper = document.getElementById("result-offer-wrapper");
  elements.offerText = document.getElementById("result-offer");
  elements.resetSection = document.getElementById("reset-section");
  elements.resetButton = document.getElementById("reset-button");
  elements.qrReader = document.getElementById("qr-reader");
  elements.qrReaderError = document.getElementById("qr-reader-error");
  elements.cancelScanButton = document.getElementById("cancel-scan-button");
  elements.manualEntrySection = document.getElementById("manual-entry-section");
  elements.manualCodeInput = document.getElementById("manual-code-input");
  elements.manualVerifyButton = document.getElementById("manual-verify-button");
  elements.startScanButton = document.getElementById("start-scan-button");
  elements.showManualEntryButton = document.getElementById(
    "show-manual-entry-button"
  );

  // Event Listeners
  elements.startScanButton.addEventListener("click", () =>
    updateUi("scanning")
  );
  elements.showManualEntryButton.addEventListener("click", () =>
    updateUi("manual_entry")
  );
  elements.resetButton.addEventListener("click", resetVerification);
  elements.cancelScanButton.addEventListener("click", resetVerification);
  elements.manualVerifyButton.addEventListener(
    "click",
    handleManualVerification
  );
  elements.manualCodeInput.addEventListener("input", () => {
    elements.manualCodeInput.value =
      elements.manualCodeInput.value.toUpperCase();
  });

  checkCode();
});

function getCodeFromUrl() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get("code") ? urlParams.get("code").toUpperCase() : null;
}

async function processRedemption(codeId, offer) {
  updateUi("loading"); // Show loading indicator during redemption

  try {
    const response = await fetch("/api.php?route=/api/redeem-code", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ code_id: codeId }),
    });
    if (!response.ok)
      throw new Error(`API request failed with status ${response.status}`);
    const result = await response.json();

    if (result.status === "SUCCESS") {
      displayResult(
        "REDUCERE CONFIRMATĂ!",
        `Codul a fost validat și înregistrat.`,
        "bg-green-100 text-green-800",
        offer
      );
    } else {
      displayResult(
        "EROARE LA CONFIRMARE",
        `A apărut o eroare la înregistrarea tranzacției. Status: ${result.status}`,
        "bg-red-100 text-red-800"
      );
    }
  } catch (error) {
    console.error("Redemption failed unexpectedly:", error);
    displayResult(
      "EROARE DE SISTEM",
      "A apărut o eroare de rețea sau de bază de date la confirmare. Vă rugăm să încercați din nou.",
      "bg-red-100 text-red-800"
    );
  }
}

async function checkCode(directCode) {
  const code = directCode || getCodeFromUrl();
  if (!code) {
    updateUi("initial");
    return;
  }

  updateUi("loading");

  try {
    const response = await fetch(`/api.php?route=/api/check-code&code=${code}`);
    if (!response.ok)
      throw new Error(`API request failed with status ${response.status}`);
    const result = await response.json();

    switch (result.status) {
      case "VALID":
        appState.currentCodeId = result.code_id;
        appState.currentCodeString = code;
        appState.currentOffer = result.offer;
        // Automatically process redemption for valid codes
        await processRedemption(appState.currentCodeId, appState.currentOffer);
        break;
      case "ALREADY_USED":
        const usedDate = new Date(result.used_at).toLocaleString("ro-RO", {
          year: "numeric",
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
        displayResult(
          "DEJA UTILIZAT",
          `Acest cod a fost folosit pe ${usedDate}. Nu poate fi reutilizat.`,
          "bg-yellow-100 text-yellow-800"
        );
        break;
      case "INVALID":
        displayResult(
          "COD INVALID",
          "Asigurați-vă că codul QR al clientului este activ și scanat corect.",
          "bg-red-100 text-red-800"
        );
        break;
      default:
        throw new Error(`Unknown status: ${result.status}`);
    }
  } catch (error) {
    console.error("Verification failed unexpectedly:", error);
    displayResult(
      "EROARE DE SISTEM",
      "A apărut o eroare de rețea sau de bază de date. Vă rugăm să încercați din nou.",
      "bg-red-100 text-red-800"
    );
  }
}

async function resetVerification() {
  if (appState.scanner && appState.scanner.isScanning) {
    try {
      await appState.scanner.stop();
    } catch (err) {
      console.error(
        "Scanner could not be stopped during reset, but proceeding.",
        err
      );
    }
  }
  appState.scanner = null;
  appState.currentCodeId = null;
  appState.currentCodeString = null;
  appState.currentOffer = null;
  elements.qrReader.innerHTML = "";
  window.history.pushState({}, document.title, window.location.pathname);
  const possibleColorClasses = [
    "bg-green-100",
    "text-green-800",
    "bg-yellow-100",
    "text-yellow-800",
    "bg-red-100",
    "text-red-800",
  ];
  elements.card.classList.remove(...possibleColorClasses);
  updateUi("initial");
}

function handleManualVerification() {
  const code = elements.manualCodeInput.value.trim().toUpperCase();
  if (code) {
    window.history.pushState({}, "", `/verify?code=${code}`);
    checkCode(code);
  } else {
    alert("Vă rugăm introduceți un cod.");
  }
}

const cancelScan = resetVerification;

function startScanner() {
  if (appState.scanner && appState.scanner.isScanning) return;

  const onScanSuccess = (decodedText, decodedResult) => {
    // Stop the scanner as soon as a code is found.
    if (appState.scanner) {
      appState.scanner
        .stop()
        .then(() => {
          let cleanCode;
          try {
            if (decodedText.toLowerCase().startsWith("http")) {
              const url = new URL(decodedText);
              cleanCode = url.searchParams.get("code") || decodedText;
            } else {
              cleanCode = decodedText;
            }
          } catch (e) {
            cleanCode = decodedText;
          }
          cleanCode = cleanCode.trim().toUpperCase();
          window.history.pushState({}, "", `/verify?code=${cleanCode}`);
          checkCode(cleanCode);
        })
        .catch((err) => {
          console.error("Error stopping scanner after success:", err);
          checkCode(decodedText); // Process code anyway
        });
    }
  };

  const config = { fps: 10, qrbox: { width: 250, height: 250 } };
  appState.scanner = new Html5Qrcode("qr-reader");
  appState.scanner
    .start({ facingMode: "environment" }, config, onScanSuccess)
    .catch((err) => {
      console.error("Unable to start QR scanner", err);
      elements.qrReader.classList.add("hidden");
      elements.qrReaderError.classList.remove("hidden");
      elements.manualEntrySection.classList.remove("hidden");
      elements.cancelScanButton.classList.remove("hidden");
    });
}

function displayResult(title, message, colorClasses, offer = null) {
  const possibleColorClasses = [
    "bg-green-100",
    "text-green-800",
    "bg-yellow-100",
    "text-yellow-800",
    "bg-red-100",
    "text-red-800",
  ];
  elements.card.classList.remove(...possibleColorClasses);
  elements.card.classList.add(...colorClasses.split(" "));
  elements.resultTitle.textContent = title;
  elements.resultMessage.textContent = message;
  elements.offerWrapper.style.display = offer ? "block" : "none";
  if (offer) elements.offerText.textContent = offer;
  updateUi("result");
}

function updateUi(newUiState) {
  appState.ui = newUiState;

  const allSections = [
    elements.initialState,
    elements.loadingIndicator,
    elements.resultDisplay,
    elements.resetSection,
    elements.qrReader,
    elements.qrReaderError,
    elements.manualEntrySection,
    elements.cancelScanButton,
  ];
  allSections.forEach((el) => el.classList.add("hidden"));

  switch (appState.ui) {
    case "loading":
      elements.loadingIndicator.classList.remove("hidden");
      break;
    case "scanning":
      elements.qrReader.classList.remove("hidden");
      elements.cancelScanButton.classList.remove("hidden");
      startScanner();
      break;
    case "manual_entry":
      elements.manualEntrySection.classList.remove("hidden");
      elements.cancelScanButton.classList.remove("hidden");
      elements.manualCodeInput.focus();
      break;
    case "result":
      elements.resultDisplay.classList.remove("hidden");
      elements.resetSection.classList.remove("hidden");
      break;
    case "initial":
    default:
      elements.initialState.classList.remove("hidden");
      break;
  }
}
