// Student only: the document catalog and the request form.
// Documents, prices, release methods and requirements all come from the database (api/documents.php).
(function () {
  const MAX_COPIES = 5;
  const docGrid = document.getElementById("docGrid");
  const requestFormPanel = document.getElementById("requestFormPanel");
  const requestsHistoryCard = document.getElementById("requestsHistoryCard");
  const form = document.getElementById("documentRequestForm");
  const documentRows = document.getElementById("requestDocumentRows");
  const requirementsList = document.getElementById("requestRequirementsList");
  const releaseSelect = document.getElementById("requestReleaseMethod");
  const paymentSelect = document.getElementById("requestPaymentMethod");

  // Opened from the landing page "Request a Document" form: the wizard shows as an overlay and leaving returns to index.html.
  let landingFlow = false;
  try { landingFlow = !!(JSON.parse(sessionStorage.getItem("colmRequestDraft") || "null") || {}).document; } catch (error) { /* storage may be off */ }
  if (landingFlow) { document.documentElement.classList.add("landing-flow"); document.body.classList.add("landing-flow"); }
  const endLandingFlow = () => { document.documentElement.classList.remove("landing-flow"); document.body.classList.remove("landing-flow"); };
  // Inside the landing page overlay (an iframe) ask the landing page to close it; opened on its own, go back to index.html.
  const backToLanding = () => {
    if (window.parent !== window) window.parent.postMessage({ colmClose: true }, window.location.origin);
    else window.location.href = "index.html";
  };
  const message = document.getElementById("requestFormMessage");
  const successPanel = document.getElementById("requestSuccess");
  const submitButton = document.getElementById("submitRequestBtn");
  const steps = [...document.querySelectorAll("[data-request-step]")];
  const stepIndicators = [...document.querySelectorAll("[data-step-indicator]")];
  const stepper = document.querySelector(".request-stepper");
  const PURPOSE_HINT = "e.g. Scholarship application, employment, transfer";

  let catalog = [];            // documents from the database
  let studentAccount = null;
  let activeStep = 1;
  let latestRequest = null;
  let messageTimer = null;
  let verifiedStudentInformationFingerprint = "";
  let studentVerificationCheckedFingerprint = "";
  let studentVerificationAttempt = 0;
  const chosenFiles = new Map();         // "rowId:requirementId" -> File
  // "rowId:requirementId" -> { state: "checking" | "accepted" | "received" | "declined", checkId, title, message, filename }
  const checks = new Map();
  let checksRunning = 0;                 // how many files are being verified right now
  const reviewedRows = new Set();        // rows where the student ticked "I have reviewed these requirements"

  // ---------- Catalog ----------
  function processingText(doc) {
    if (doc.processing_max_days === 0) return "Same business day";
    return doc.processing_min_days === doc.processing_max_days
      ? `${doc.processing_min_days} working days` : `${doc.processing_min_days}-${doc.processing_max_days} working days`;
  }

  function dateKey(date) {
    const part = value => String(value).padStart(2, "0");
    return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}`;
  }

  function addProcessingDays(days) {
    const date = new Date();
    date.setHours(12, 0, 0, 0);
    while (date.getDay() === 0 || date.getDay() === 6) date.setDate(date.getDate() + 1);
    for (let remaining = Math.max(0, days); remaining > 0;) {
      date.setDate(date.getDate() + 1);
      if (date.getDay() !== 0 && date.getDay() !== 6) remaining--;
    }
    return date;
  }

  function releaseDateFor(doc) {
    const start = addProcessingDays(doc.processing_min_days), end = addProcessingDays(doc.processing_max_days);
    const label = start.getTime() === end.getTime()
      ? Portal.formatDate(dateKey(start)) : `${Portal.formatDate(dateKey(start))} – ${Portal.formatDate(dateKey(end))}`;
    return { start, end, label };
  }

  function renderCatalog() {
    docGrid.innerHTML = catalog.length ? catalog.map(doc => `
      <div class="doc-item">
        <div class="doc-card">
          <h3>${escapeHtml(doc.name)}</h3>
          <span class="doc-price">${doc.price}</span>
          <p class="doc-desc">${escapeHtml(doc.description || "")}</p>
          <button class="doc-request-btn" type="button" data-document-id="${doc.id}" ${doc.pending_request ? "disabled" : ""}>
            ${doc.pending_request ? "Request in Progress" : "Select Document"}
          </button>
          ${doc.pending_request ? `<p class="request-date-note">Request ${escapeHtml(doc.pending_tracking_no)} must be completed or rejected before you can request this document again.</p>` : ""}
        </div>
        <div class="doc-card-meta">
          <p><strong>Processing Days:</strong> ${escapeHtml(processingText(doc))}</p>
          <p class="meta-label"><strong>Requirements:</strong></p>
          ${doc.credential_form_required ? '<p class="meta-check"><span class="material-symbol">check_circle</span> Completed credential request form</p>' : ""}
          ${doc.student_verification_form_required ? '<p class="meta-check"><span class="material-symbol">check_circle</span> Student Verification form</p>' : ""}
          ${doc.requirements.map(req => `<p class="meta-check"><span class="material-symbol">check_circle</span> ${escapeHtml(req.name)}</p>`).join("")}
          <p class="meta-label"><strong>Available Released Method:</strong></p>
          ${doc.release_methods.map(method => `<p class="meta-check"><span class="material-symbol">check_circle</span> ${escapeHtml(method)}</p>`).join("")}
        </div>
      </div>`).join("") : '<p class="requests-empty">No documents are available right now.</p>';
    document.dispatchEvent(new CustomEvent("table:rendered"));
  }

  docGrid.addEventListener("click", event => {
    const button = event.target.closest("[data-document-id]");
    if (button) startRequest(button.dataset.documentId);
  });

  // ---------- Messages and steps ----------
  function showMessage(text) {
    message.setAttribute("role", "alert");
    message.textContent = text;
    clearTimeout(messageTimer);
    messageTimer = setTimeout(clearMessage, 8000);
  }
  function clearMessage() {
    clearTimeout(messageTimer);
    message.setAttribute("role", "status");
    message.textContent = "";
  }
  function showStep(step) {
    if (step === 2) step = 1;          // Purpose & Format now live in step 1
    const hasRequirementsStep = requiresRequirementsStep(chosenDocuments());
    if (step === 3 && !hasRequirementsStep) step = 4;
    activeStep = step;
    steps.forEach(section => { section.hidden = Number(section.dataset.requestStep) !== step; });
    updateStepIndicators(step, hasRequirementsStep);
    updateNextButtons();
    clearMessage();
  }

  function updateStepIndicators(step, hasRequirementsStep) {
    // Visible steps: 1 Document & Purpose, [3 Requirements], 4 Review, 5 Success (step 2 was merged into step 1).
    const visible = stepIndicators.filter(i => { const n = Number(i.dataset.stepIndicator); return n !== 2 && (hasRequirementsStep || n !== 3); });
    stepper.style.setProperty("--request-step-count", String(visible.length));
    const current = step === 2 ? 1 : step;
    stepIndicators.forEach(indicator => {
      const number = Number(indicator.dataset.stepIndicator);
      const position = visible.indexOf(indicator);
      indicator.hidden = position === -1;
      if (position !== -1) indicator.querySelector("span").textContent = String(position + 1);
      indicator.classList.toggle("active", number === current);
      indicator.classList.toggle("complete", number < current);
      indicator.setAttribute("aria-current", number === current ? "step" : "false");
    });
  }

  // ---------- Document rows (step 1) ----------
  function chosenDocuments() {
    return [...documentRows.querySelectorAll(".request-document-row")].flatMap(row => {
      const doc = catalog.find(item => item.id === Number(row.querySelector(".request-document-select").value));
      const quantity = Number(row.querySelector(".request-quantity").value);
      if (!doc || !Number.isInteger(quantity) || quantity < 1 || quantity > MAX_COPIES) return [];
      const purposeChoice = row.querySelector(".request-purpose").value;
      const purposeOther = row.querySelector(".request-purpose-other").value.trim();
      return [{ ...doc, rowId: row.dataset.rowId, quantity, purposeChoice, purposeOther, purpose: purposeChoice === "Others" ? purposeOther : purposeChoice, lineTotal: doc.price * quantity, release: releaseDateFor(doc) }];
    });
  }

  // "Others" shows the extra text box; any other purpose hides and empties it.
  documentRows.addEventListener("change", event => {
    if (!event.target.matches(".request-purpose")) return;
    const other = event.target.closest(".request-purpose-field").querySelector(".request-purpose-other");
    other.hidden = event.target.value !== "Others";
    if (other.hidden) other.value = "";
    else other.focus();
    updateSummary();
  });

  // Keep this list the same as PURPOSE_CHOICES in backend/release.php (the server checks it again).
  const PURPOSE_CHOICES = ["Employment", "Evaluation", "Board Examination", "Scholarship", "Transfer to Another School", "Further Studies", "Personal Records"];

  function addDocumentRow(documentId = "") {
    const row = document.createElement("div");
    const id = (window.crypto && typeof crypto.randomUUID === "function") ? crypto.randomUUID()
      : "r" + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    const fixedDocument = catalog.find(doc => doc.id === Number(documentId) && !doc.pending_request);
    row.className = "request-document-row";
    row.dataset.rowId = id;
    if (fixedDocument) row.dataset.fixedDocument = "true";
    row.innerHTML = `
      <div class="request-field request-document-field">
        <label for="doc-${id}-${fixedDocument ? "display" : "select"}">Requested document</label>
        ${fixedDocument ? `<output class="request-document-fixed" id="doc-${id}-display">${escapeHtml(fixedDocument.name)}</output>` : ""}
        <select class="request-document-select" id="doc-${id}-select" ${fixedDocument ? "hidden" : ""} required>
          <option value="">-- Choose a document --</option>
          ${catalog.filter(doc => fixedDocument ? doc.id === fixedDocument.id : true).map(doc => `<option value="${doc.id}" ${doc.pending_request ? "disabled" : ""}>${escapeHtml(doc.name)}${doc.pending_request ? " — request in progress" : ""}</option>`).join("")}
        </select>
      </div>
      <div class="request-field request-quantity-field">
        <label for="qty-${id}">Number of copies</label>
        <input class="request-quantity" id="qty-${id}" type="number" min="1" max="${MAX_COPIES}" step="1" value="1" required>
      </div>
      <div class="request-field request-purpose-field">
        <label for="purpose-${id}">Specific purpose of request</label>
        <select class="request-purpose" id="purpose-${id}" required>
          <option value="">-- Choose a purpose --</option>
          ${PURPOSE_CHOICES.map(p => `<option value="${p}">${p}</option>`).join("")}
          <option value="Others">Others</option>
        </select>
        <input class="request-purpose-other request-text-input" id="purposeOther-${id}" type="text" maxlength="255" placeholder="Please specify your purpose" aria-label="Specify the purpose" hidden>
      </div>
      <button class="request-remove-btn" type="button" aria-label="Remove document" ${fixedDocument ? "hidden" : ""}><span class="material-symbol">delete</span></button>`;
    row.querySelector(".request-document-select").value = documentId;
    documentRows.append(row);
    updateSummary();
  }

  function updateSummary() {
    const picked = new Set([...documentRows.querySelectorAll(".request-document-select")].map(s => s.value).filter(Boolean));
    documentRows.querySelectorAll(".request-document-row").forEach(row => {
      const select = row.querySelector(".request-document-select");
      [...select.options].forEach(option => {
        const doc = catalog.find(item => String(item.id) === option.value);
        option.disabled = !!option.value && (!!doc?.pending_request || (option.value !== select.value && picked.has(option.value)));
      });
    });
    const items = chosenDocuments();

    // Release methods that every chosen document offers
    const common = items.length ? items[0].release_methods.filter(m => items.every(i => i.release_methods.includes(m))) : [];
    const current = releaseSelect.value;
    // Document format: Hard copy, Soft copy (PDF) or both. Soft copy needs every chosen document to offer it (the admin sets this in the Catalog).
    const formats = common.includes("Softcopy") && common.includes("Hardcopy") ? ["Hardcopy", "Softcopy", "Both"] : common;
    releaseSelect.innerHTML = formats.length ? formats.map(m => `<option value="${m}">${formatLabel(m)}</option>`).join("") : '<option value="">Choose a document first</option>';
    releaseSelect.disabled = formats.length === 0;
    // The Document Format field is hidden. Hard copy is used when the document offers it, otherwise the first format offered.
    releaseSelect.value = formats.includes(current) ? current : (formats.includes("Hardcopy") ? "Hardcopy" : (formats[0] || ""));
    // Only one format available (e.g. hard copy only): no dropdown, just show it as text.
    const fixedFormat = document.getElementById("requestReleaseMethodFixed");
    const single = formats.length === 1;
    releaseSelect.hidden = single;
    if (fixedFormat) {
      fixedFormat.hidden = !single;
      fixedFormat.textContent = single ? formatLabel(formats[0]) : "";
    }
    const hint = document.getElementById("formatHint");
    if (hint) hint.textContent = items.length && !common.includes("Softcopy") ? "A soft copy (PDF) is not offered for the selected document(s)." : (items.length ? "A soft copy is released after the payment and the registrar's processing. You can download it from your dashboard." : "");

    const hasRequirementsStep = requiresRequirementsStep(items);
    const uploadRequirements = items.some(item => item.requirements.length > 0);
    document.getElementById("requestUploadFormatsNote").hidden = !uploadRequirements;
    renderRequirements(
      requirementsStepItems(items),
      requirementsList,
      needsCredentialForm(items) || needsStudentVerificationForm(items)
        ? "Complete the required form above. No additional file upload is needed for these documents."
        : "No additional requirements for the selected document(s)."
    );
    updateStepIndicators(activeStep, hasRequirementsStep);
    const purposeNext = document.getElementById("purposeNextButton");
    purposeNext.dataset.nextStep = hasRequirementsStep ? "3" : "4";
    purposeNext.innerHTML = 'Proceed <span aria-hidden="true"></span>';
    document.querySelector('[data-request-step="4"] [data-previous-step]').dataset.previousStep = hasRequirementsStep ? "3" : "2";
    syncCredentialForm(items);
    syncStudentVerificationForm(items);
    refreshStudentVerificationState(items);
    updateNextButtons();
  }

  function isCredentialDocument(item) {
    const name = item.name.toLowerCase().replace(/[^a-z0-9]/g, "");
    return name.startsWith("transcriptofrecords") || name === "honorabledismissal" || name.startsWith("form137");
  }

  // The Student Verification form was removed from the process; Certification documents have no Requirements step.
  function isStudentVerificationDocument(item) {
    return false;
  }

  function needsCredentialForm(items) {
    return items.some(isCredentialDocument);
  }

  function needsStudentVerificationForm(items) {
    return items.some(isStudentVerificationDocument);
  }

  function itemNeedsRequirementsStep(item) {
    return isCredentialDocument(item) || isStudentVerificationDocument(item) || item.requirements.length > 0;
  }

  function requiresRequirementsStep(items) {
    return items.some(itemNeedsRequirementsStep);
  }

  function requirementsStepItems(items) {
    return items.filter(itemNeedsRequirementsStep);
  }

  function syncCredentialForm(items = chosenDocuments()) {
    const enabled = needsCredentialForm(items);
    const container = document.getElementById("credentialFormSection");
    container.hidden = !enabled;
    if (enabled && studentAccount) {
      document.getElementById("credentialStudentNo").value = studentAccount.student_no || studentAccount.username || "";
      document.getElementById("credentialCourse").value = studentAccount.course || "";
      const gradeLevel = document.getElementById("credentialGradeLevel");
      if (!gradeLevel.value && studentAccount.year_level) gradeLevel.value = String(studentAccount.year_level);
      const mobile = document.getElementById("credentialMobile");
      if (!mobile.value && studentAccount.contact_no) mobile.value = studentAccount.contact_no;
    }
    const requestedDocuments = items.filter(isCredentialDocument).map(item => item.name);
    document.getElementById("credentialRequestedDocuments").innerHTML = requestedDocuments
      .map(name => `<span>${escapeHtml(name)}</span>`).join("");
    document.getElementById("credentialClaimDocuments").textContent = requestedDocuments.join(", ") || "Select a document";
    updateCredentialPreview();
  }

  function syncStudentVerificationForm(items = chosenDocuments()) {
    const enabled = needsStudentVerificationForm(items);
    document.getElementById("studentVerificationFormSection").hidden = !enabled;
    if (!enabled || !studentAccount) return;
    const values = {
      studentVerificationId: studentAccount.student_no || studentAccount.username || "",
      studentVerificationName: studentAccount.full_name || "",
      studentVerificationCourse: studentAccount.course || "",
      studentVerificationAcademicYear: studentAccount.academic_year || "",
      studentVerificationGradeLevel: studentAccount.year_label || (studentAccount.year_level ? String(studentAccount.year_level) : "")
    };
    // Student ID and Student Name always come from the student's profile and cannot be edited.
    const lockedFields = new Set(["studentVerificationId", "studentVerificationName"]);
    Object.entries(values).forEach(([id, value]) => {
      const field = document.getElementById(id);
      if (lockedFields.has(id)) {
        field.value = value;
        field.readOnly = true;
        field.setAttribute("aria-readonly", "true");
        field.tabIndex = -1;
        field.classList.add("is-locked");
      } else if (!field.value) {
        field.value = value;
      }
    });
  }

  function studentVerificationFormData(items = chosenDocuments()) {
    if (!needsStudentVerificationForm(items)) return null;
    return {
      student_id: document.getElementById("studentVerificationId").value.trim(),
      student_name: document.getElementById("studentVerificationName").value.trim(),
      course_program: document.getElementById("studentVerificationCourse").value.trim(),
      academic_year: document.getElementById("studentVerificationAcademicYear").value.trim(),
      grade_level: document.getElementById("studentVerificationGradeLevel").value.trim()
    };
  }

  function studentVerificationFingerprint(items = chosenDocuments()) {
    return JSON.stringify({
      documents: items.filter(isStudentVerificationDocument).map(item => item.id).sort((a, b) => a - b),
      form: studentVerificationFormData(items)
    });
  }

  function refreshStudentVerificationState(items = chosenDocuments()) {
    const status = document.getElementById("studentVerificationStatus");
    const statusText = document.getElementById("studentVerificationStatusText");
    const button = document.getElementById("requirementsNextButton");
    if (!needsStudentVerificationForm(items)) {
      verifiedStudentInformationFingerprint = "";
      studentVerificationCheckedFingerprint = "";
      statusText.textContent = "";
      status.className = "form-message";
      button.innerHTML = 'Review Request <span aria-hidden="true"></span>';
      return;
    }
    const currentFingerprint = studentVerificationFingerprint(items);
    if ((verifiedStudentInformationFingerprint && verifiedStudentInformationFingerprint !== currentFingerprint)
      || (studentVerificationCheckedFingerprint && studentVerificationCheckedFingerprint !== currentFingerprint)) {
      verifiedStudentInformationFingerprint = "";
      studentVerificationCheckedFingerprint = "";
      status.className = "form-message";
      statusText.textContent = "The information changed since verification. Verify again to refresh the results.";
    }
    button.innerHTML = verifiedStudentInformationFingerprint === currentFingerprint
      ? 'Continue to Review <span aria-hidden="true"></span>'
      : 'Verify Student Information <span aria-hidden="true"></span>';
  }

  function studentVerificationFormProblem(items = chosenDocuments()) {
    if (!needsStudentVerificationForm(items)) return "";
    const data = studentVerificationFormData(items);
    if (!document.getElementById("studentVerificationId").value.trim()
      || !document.getElementById("studentVerificationName").value.trim()
      || !document.getElementById("studentVerificationCourse").value.trim()) {
      return "Your Student ID, student name, and Course/Program must be set in your student profile before requesting a Certificate.";
    }
    if (!data.academic_year) return "Enter the academic year in the Student Verification form.";
    const schoolYear = data.academic_year.match(/^(\d{4})-(\d{4})$/);
    if (!schoolYear || Number(schoolYear[2]) !== Number(schoolYear[1]) + 1) {
      return "Enter the academic year as consecutive years, for example 2025-2026.";
    }
    if (!data.grade_level) return "Enter the grade level in the Student Verification form.";
    return "";
  }

  function updateCredentialPreview() {
    const firstName = document.getElementById("credentialFirstName").value.trim();
    const middleName = document.getElementById("credentialMiddleName").value.trim();
    const lastName = document.getElementById("credentialLastName").value.trim();
    document.getElementById("credentialClaimName").textContent = [firstName, middleName, lastName].filter(Boolean).join(" ") || "To be completed";
  }

  function credentialFormData(items = chosenDocuments()) {
    if (!needsCredentialForm(items)) return null;
    const selectedPurpose = form.querySelector('[name="credentialPurpose"]:checked');
    const graduated = form.querySelector('[name="credentialGraduated"]:checked');
    return {
      last_name: document.getElementById("credentialLastName").value.trim(),
      first_name: document.getElementById("credentialFirstName").value.trim(),
      middle_name: document.getElementById("credentialMiddleName").value.trim(),
      address: document.getElementById("credentialAddress").value.trim(),
      birthdate: document.getElementById("credentialBirthdate").value,
      gender: document.getElementById("credentialGender").value,
      student_no: document.getElementById("credentialStudentNo").value.trim(),
      grade_level: document.getElementById("credentialGradeLevel").value.trim(),
      strand: document.getElementById("credentialStrand").value.trim(),
      course: document.getElementById("credentialCourse").value.trim(),
      requested_documents: items.filter(isCredentialDocument).map(item => item.name),
      graduated: graduated?.value || "",
      graduation_date: document.getElementById("credentialGraduationDate").value,
      last_attended_school_year: document.getElementById("credentialLastSchoolYear").value.trim(),
      elementary_school: document.getElementById("credentialElementary").value.trim(),
      junior_high_school: document.getElementById("credentialJuniorHigh").value.trim(),
      senior_high_school: document.getElementById("credentialSeniorHigh").value.trim(),
      telephone: document.getElementById("credentialTelephone").value.trim(),
      mobile: document.getElementById("credentialMobile").value.trim(),
      purpose: selectedPurpose?.value || "",
      other_purpose: document.getElementById("credentialOtherPurpose").value.trim(),
      signature: document.getElementById("credentialSignature").value.trim(),
      notes: document.getElementById("credentialNotes").value.trim()
    };
  }

  function credentialFormProblem(items = chosenDocuments()) {
    if (!needsCredentialForm(items)) return "";
    const data = credentialFormData(items);
    const today = dateKey(new Date());
    const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value) && dateKey(new Date(`${value}T00:00:00`)) === value;
    if (!data.last_name || !data.first_name || data.address.length < 5) return "Complete the name and address fields in the credential request form.";
    if (!validDate(data.birthdate) || data.birthdate > today) return "Enter a valid birthdate in the credential request form.";
    if (!["Female", "Male", "Prefer not to say"].includes(data.gender)) return "Choose a gender in the credential request form.";
    if (!data.mobile.replace(/\D/g, "").length || data.mobile.replace(/\D/g, "").length < 7) return "Enter a valid mobile number in the credential request form.";
    if (!["yes", "no"].includes(data.graduated)) return "Select your graduation status in the credential request form.";
    if (data.graduated === "yes" && (!validDate(data.graduation_date) || data.graduation_date > today)) return "Enter a valid graduation date in the credential request form.";
    const schoolYear = data.last_attended_school_year.match(/^(\d{4})-(\d{4})$/);
    if (data.graduated === "no" && (!schoolYear || Number(schoolYear[2]) !== Number(schoolYear[1]) + 1)) return "Enter the school year last attended (for example 2025-2026).";
    if (!data.purpose || (data.purpose === "Others" && data.other_purpose.length < 3)) return "Choose or specify the purpose in the credential request form.";
    if (data.signature.length < 3) return "Type your printed name under your signature in the credential request form.";
    if (!window.SignaturePad || window.SignaturePad.isEmpty()) return "Draw your electronic signature in the signature box.";
    return "";
  }

  function releaseLabel(items) {
    if (!items.length) return "Choose a document first";
    const first = new Date(Math.min(...items.map(i => i.release.start.getTime())));
    const last = new Date(Math.max(...items.map(i => i.release.end.getTime())));
    return first.getTime() === last.getTime() ? Portal.formatDate(dateKey(first))
      : `${Portal.formatDate(dateKey(first))} – ${Portal.formatDate(dateKey(last))}`;
  }

  // ---------- Requirements and uploads (Purpose step) ----------
  const fileKey = (rowId, requirementId) => `${rowId}:${requirementId}`;

  function renderRequirements(items, container, emptyMessage) {
    container.innerHTML = items.filter(item => item.requirements.length).map(item => `
      <section class="request-requirement-group">
        <h4>${escapeHtml(item.name)}</h4>
        <ul>${item.requirements.map(req => {
          const key = fileKey(item.rowId, req.id);
          const inputId = `file-${item.rowId}-${req.id}`;
          const file = chosenFiles.get(key);
          const types = req.allowed_types.split(",").map(t => t.toUpperCase()).join(", ");
          return `<li class="request-requirement-item">
            <div class="request-requirement-heading"><strong>${escapeHtml(req.name)}</strong>
              <span class="request-required-badge">${req.is_required ? "Required" : "Optional"}</span></div>
            <p class="request-requirement-description">${escapeHtml(req.description || "Upload a file that satisfies this requirement.")}</p>
            <input class="request-required-file" id="${inputId}" type="file" data-key="${escapeHtml(key)}" data-req-id="${req.id}"
              data-max-mb="${req.max_size_mb}" data-types="${escapeHtml(req.allowed_types)}" accept=".${req.allowed_types.split(",").join(",.")}"
              aria-label="Upload ${escapeHtml(req.name)} for ${escapeHtml(item.name)}">
            <div class="upload-box" data-input-id="${inputId}" data-key="${escapeHtml(key)}" data-types="${escapeHtml(req.allowed_types)}" data-max-mb="${req.max_size_mb}" aria-live="polite">${uploadBoxHtml(checks.get(key), chosenFiles.get(key), inputId, types, req.max_size_mb, key)}</div>
            </li>`;
        }).join("")}</ul>
        <label class="request-confirm-option"><input type="checkbox" class="request-requirement-confirm" data-row-id="${escapeHtml(item.rowId)}"
          ${reviewedRows.has(item.rowId) ? "checked" : ""}> I have reviewed these requirements.</label>
      </section>`).join("") || `<p class="request-date-note">${escapeHtml(emptyMessage)}</p>`;
  }

  function redrawUploadBox(key) {
    const box = [...form.querySelectorAll(".upload-box")].find(b => b.dataset.key === key);
    if (!box) return;
    const types = box.dataset.types.split(",").map(t => t.toUpperCase()).join(", ");
    box.innerHTML = uploadBoxHtml(checks.get(key), chosenFiles.get(key), box.dataset.inputId, types, box.dataset.maxMb, key);
  }

  // The student can review the request only when every required file is VERIFIED and present.
  // (The server checks this again when the request is submitted - this only enables / disables the button.)
  function requirementsReady(items = chosenDocuments()) {
    if (checksRunning > 0) return false;
    if (!items.length) return false;
    if (missingRequirements(items).length > 0) return false;
    return !items.some(item => item.requirements.some(req => {
      const state = checks.get(fileKey(item.rowId, req.id));
      return state && state.state !== "verified";          // a file that is declined, pending or being checked blocks too
    }));
  }

  function updateNextButtons() {
    const items = chosenDocuments();
    const ready = requirementsReady(items) && !credentialFormProblem(items);
    const hasRequirementsStep = requiresRequirementsStep(items);
    document.querySelectorAll("[data-next-step]").forEach(button => {
      if (button.id === "purposeNextButton") {
        const purposeReady = checksRunning === 0;
        button.disabled = !purposeReady;
        button.title = purposeReady ? "" : "Please wait while your documents are being verified.";
        return;
      }
      if (Number(button.dataset.nextStep) < 3) return;
      button.disabled = !ready;
      button.title = ready ? "" : (checksRunning > 0 ? "Please wait while your documents are being verified." : "Upload and verify all required documents first.");
    });
  }

  const VERIFICATION_FIELD_IDS = {
    "Student ID": "studentVerificationId",
    "Student Name": "studentVerificationName",
    "Course/Program": "studentVerificationCourse",
    "Academic Year": "studentVerificationAcademicYear",
    "Grade Level": "studentVerificationGradeLevel"
  };

  function clearVerificationMarks() {
    Object.values(VERIFICATION_FIELD_IDS).forEach(id => {
      const field = document.getElementById(id);
      field.removeAttribute("data-verify");
      field.removeAttribute("aria-invalid");
    });
  }

  // Green for matching fields, red for fields that do not match the student profile.
  function markVerificationFields(checks) {
    clearVerificationMarks();
    (checks || []).forEach(check => {
      const id = VERIFICATION_FIELD_IDS[check.field];
      if (!id) return;
      const field = document.getElementById(id);
      field.dataset.verify = check.passed ? "ok" : "bad";
      if (!check.passed) field.setAttribute("aria-invalid", "true");
    });
  }

  function showVerificationDialog(verified, checks) {
    const wrong = (checks || []).filter(check => !check.passed);
    const dialog = document.createElement("dialog");
    dialog.className = `request-cancel-confirm-dialog verification-dialog ${verified ? "is-verified" : "is-wrong"}`;
    dialog.setAttribute("aria-labelledby", "verificationDialogTitle");
    dialog.innerHTML = `
      <div class="logout-confirm-icon" aria-hidden="true"><span class="material-symbol">${verified ? "check_circle" : "error"}</span></div>
      <h2 id="verificationDialogTitle">${verified ? "Verified" : "Wrong Information"}</h2>
      <p>${verified
        ? "Your student information matches your student profile."
        : "Please correct the fields marked in red, then verify again."}</p>
      ${wrong.length ? `<ul class="verification-dialog-list">${wrong.map(check =>
        `<li><strong>${escapeHtml(check.field)}</strong>: ${escapeHtml(check.result)}</li>`).join("")}</ul>` : ""}
      <div class="logout-confirm-actions">
        <button class="request-next-btn" type="button" data-close-verification>${verified ? "Continue" : "OK"}</button>
      </div>`;
    document.body.append(dialog);
    dialog.showModal();
    return new Promise(resolve => {
      const finish = () => { dialog.close(); dialog.remove(); resolve(); };
      dialog.querySelector("[data-close-verification]").addEventListener("click", finish, { once: true });
      dialog.addEventListener("cancel", event => { event.preventDefault(); finish(); }, { once: true });
    });
  }

  async function verifyStudentInformation() {
    const items = chosenDocuments();
    if (checksRunning > 0) { showMessage("Please wait while your required files are being verified."); return; }
    const button = document.getElementById("requirementsNextButton");
    const status = document.getElementById("studentVerificationStatus");
    const statusText = document.getElementById("studentVerificationStatusText");
    const spinner = document.getElementById("studentVerificationSpinner");
    const attempt = ++studentVerificationAttempt;
    const fingerprint = studentVerificationFingerprint(items);
    button.disabled = true;
    status.className = "form-message";
    status.setAttribute("aria-busy", "true");
    statusText.textContent = "Verifying your information against your student profile...";
    spinner.hidden = false;
    try {
      const result = await Api.postJson("requests.php", {
        student_verification_form: studentVerificationFormData(items),
        items: items.map(item => ({ document_id: item.id }))
      }, { action: "verify_student_form" });
      if (attempt !== studentVerificationAttempt || requestFormPanel.hidden) return;
      if (fingerprint !== studentVerificationFingerprint()) {
        status.className = "form-message error";
        statusText.textContent = "The information changed during verification. Verify it again.";
        return;
      }
      studentVerificationCheckedFingerprint = fingerprint;
      status.className = result.verified ? "form-message success" : "form-message error";
      statusText.textContent = result.verified
        ? `Information verified against your profile.\n${result.checks.map(check => `PASS — ${check.field}: ${check.result}`).join("\n")}`
        : `Verification found information that needs correction. Update the fields marked CHECK, then verify again.\n${result.checks.map(check => `${check.passed ? "PASS" : "CHECK"} — ${check.field}: ${check.result}`).join("\n")}`;
      markVerificationFields(result.checks);
      if (result.verified) {
        verifiedStudentInformationFingerprint = fingerprint;
        updateSummary();
        await showVerificationDialog(true, result.checks);
        if (attempt === studentVerificationAttempt && !requestFormPanel.hidden) goToStep(4);
      } else {
        verifiedStudentInformationFingerprint = "";
        await showVerificationDialog(false, result.checks);
        const firstWrong = document.querySelector('#studentVerificationFormSection [data-verify="bad"]');
        if (firstWrong && !firstWrong.readOnly) firstWrong.focus();
      }
    } catch (error) {
      if (attempt !== studentVerificationAttempt || requestFormPanel.hidden) return;
      status.className = "form-message error";
      statusText.textContent = error.message;
      showMessage(error.message);
    } finally {
      if (attempt === studentVerificationAttempt) {
        spinner.hidden = true;
        status.setAttribute("aria-busy", "false");
        button.disabled = false;
        updateNextButtons();
      }
    }
  }

  function forgetFile(key, input) {
    const old = checks.get(key);
    if (old && old.checkId) Api.postJson("verify.php", { id: old.checkId }, { action: "discard" }).catch(() => {});
    chosenFiles.delete(key);
    checks.delete(key);
    if (input) input.value = "";
    updateNextButtons();
  }

  // Shows the result of a check: the box, a message, and enables / disables the Next button.
  function showCheckResult(key, state) {
    checks.set(key, state);
    const messages = { verified: ["success", "Document verified"], declined: ["error", "Verification declined"],
                       needs_correction: ["error", "Needs correction"], pending: ["warning", "Verification not completed"] };
    const [type, title] = messages[state.state];
    Portal.toast(state.message, type, title);
  }

  // Sends the file to the server for verification. The result decides if the student can continue.
  async function verifyFile(key, input, file) {
    const old = checks.get(key);
    chosenFiles.set(key, file);
    checks.set(key, { state: "checking", checkId: null });
    redrawUploadBox(key);
    checksRunning++;
    updateNextButtons();
    try {
      const result = await verifyUpload(file, input.dataset.reqId, old && old.checkId);
      if (chosenFiles.get(key) !== file) {                             // the student picked another file meanwhile
        if (result.id) Api.postJson("verify.php", { id: result.id }, { action: "discard" }).catch(() => {});
        return;
      }
      showCheckResult(key, stateFromCheck(result));
    } catch (error) {
      if (chosenFiles.get(key) !== file) return;
      showCheckResult(key, stateFromError(error));
    } finally {
      checksRunning = Math.max(0, checksRunning - 1);
      redrawUploadBox(key);
      updateNextButtons();
    }
  }

  // "Try Again" after "Verification not completed". The stored file is checked again; if it was never stored the file is sent again.
  async function retryFile(key, input) {
    const state = checks.get(key);
    const file = chosenFiles.get(key);
    if (!file) return;
    if (!state || !state.checkId) { verifyFile(key, input, file); return; }
    checks.set(key, { state: "checking", checkId: state.checkId });
    redrawUploadBox(key);
    checksRunning++;
    updateNextButtons();
    try {
      showCheckResult(key, stateFromCheck(await retryUpload(state.checkId)));
    } catch (error) {
      showCheckResult(key, { ...stateFromError(error), checkId: state.checkId });
    } finally {
      checksRunning = Math.max(0, checksRunning - 1);
      redrawUploadBox(key);
      updateNextButtons();
    }
  }

  // Which required documents are still missing or not yet verified? Returns a list of names.
  function missingRequirements(items) {
    return items.flatMap(item => item.requirements
      .filter(req => req.is_required && !isAccepted(fileKey(item.rowId, req.id)))
      .map(req => `${item.name} - ${req.name}`));
  }
  const isAccepted = key => isVerifiedState(checks.get(key));

  // ---------- Moving between steps ----------
  function purposeProblem(value) {
    const purpose = value.trim();
    if (!purpose) return "Please enter the specific purpose of your request.";
    if (purpose.length < 3) return "Please enter a purpose with at least 3 characters.";
    const letters = purpose.match(/[a-z]/gi) || [];
    const vowels = purpose.match(/[aeiou]/gi) || [];
    if (!letters.length || /(.)\1{4,}/i.test(purpose) || (letters.length >= 8 && vowels.length / letters.length < 0.16)) {
      return "Please enter a meaningful purpose, not random characters.";
    }
    return "";
  }

  function goToStep(next) {
    const selects = [...documentRows.querySelectorAll(".request-document-select")];
    const items = chosenDocuments();
    if (!items.length || items.length !== selects.length) { showStep(1); showMessage("Choose a document and a valid number of copies (1 to 5) for every row."); return; }
    const pending = items.find(item => item.pending_request);
    if (pending) {
      showStep(1);
      showMessage(`You already have a request for "${pending.name}" in progress (${pending.pending_tracking_no}). You can request it again after it is completed or rejected.`);
      return;
    }
    if (next >= 2 && !releaseSelect.value) { showStep(1); showMessage("The selected documents do not share a release method. Submit them in separate requests."); return; }
    if (next >= 3) {
      for (const item of items) {
        const row = documentRows.querySelector(`[data-row-id="${item.rowId}"]`);
        const input = item.purposeChoice === "Others" ? row.querySelector(".request-purpose-other") : row.querySelector(".request-purpose");
        const problem = item.purposeChoice ? purposeProblem(item.purpose) : "Please choose the purpose of your request.";
        if (problem) {
          showStep(1);
          input.setAttribute("aria-invalid", "true");
          input.focus();
          showMessage(`${item.name}: ${problem}`);
          return;
        }
        input.removeAttribute("aria-invalid");
      }
    }
    if (next >= 4) {
      const requirementsStep = requiresRequirementsStep(items) ? 3 : 2;
      const credentialProblem = credentialFormProblem(items);
      if (credentialProblem) { showStep(requirementsStep); showMessage(credentialProblem); return; }
      const verificationProblem = studentVerificationFormProblem(items);
      if (verificationProblem) { showStep(requirementsStep); showMessage(verificationProblem); return; }
      if (needsStudentVerificationForm(items)
        && verifiedStudentInformationFingerprint !== studentVerificationFingerprint(items)) {
        showStep(requirementsStep);
        showMessage("Verify the Student Verification information against your profile before continuing.");
        return;
      }
      if (checksRunning > 0) { showStep(requirementsStep); showMessage("Please wait while your documents are being verified."); return; }
      const notOk = items.flatMap(item => item.requirements
        .filter(req => { const st = checks.get(fileKey(item.rowId, req.id)); return st && st.state !== "verified"; })
        .map(req => `${item.name} - ${req.name}`));
      if (notOk.length) {
        showStep(requirementsStep);
        const blocked = items.flatMap(item => item.requirements
          .map(req => ({ item, req, state: checks.get(fileKey(item.rowId, req.id)) }))
          .filter(entry => entry.state && entry.state.state !== "verified"))[0];
        if (blocked && blocked.state.state === "pending") {
          showMessage(`Cannot continue to Review: "${notOk[0]}" is pending. ${blocked.state.message}`);
        } else {
          showMessage(`Cannot continue to Review: the document for "${notOk[0]}" has not passed the pre-check. Please upload a corrected file before continuing.`);
        }
        return;
      }
      const missing = missingRequirements(items);
      if (missing.length) {
        showStep(requirementsStep);
        showMessage(`Required document missing: ${missing[0]}${missing.length > 1 ? ` (and ${missing.length - 1} more)` : ""}. Please upload all required documents before continuing.`);
        return;
      }
      if (!items.every(item => !item.requirements.length || reviewedRows.has(item.rowId))) {
        showStep(requirementsStep);
        showMessage("Confirm that you have reviewed the listed requirements for every selected document.");
        return;
      }
    }
    if (next === 4) fillReview(items);
    showStep(next);
  }

  function fillReview(items) {
    const fileNames = items.flatMap(item => item.requirements
      .map(req => { const key = fileKey(item.rowId, req.id); const file = chosenFiles.get(key); return file && isAccepted(key) ? `${item.name} - ${req.name}: ${file.name}` : null; })
      .filter(Boolean));
    const credentialReview = document.getElementById("credentialReview");
    credentialReview.hidden = !needsCredentialForm(items);
    if (!credentialReview.hidden) {
      const data = credentialFormData(items);
      credentialReview.querySelector("strong").textContent = `${data.first_name} ${data.last_name} · ${data.requested_documents.join(", ")}`;
    }
    document.getElementById("reviewAttachments").textContent = fileNames.join(", ") || "None";
    document.getElementById("requestReviewRows").innerHTML = items.map(item => `
      <tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.purpose)}</td><td>${item.quantity}</td><td>${Portal.formatMoney(item.price)}</td>
      <td>${Portal.formatMoney(item.lineTotal)}</td></tr>`).join("");
    document.getElementById("reviewTotal").textContent = Portal.formatMoney(items.reduce((sum, item) => sum + item.lineTotal, 0));
  }

  function startRequest(documentId = "") {
    studentVerificationAttempt++;
    Portal.goToPage("My Requests");
    requestFormPanel.hidden = false;
    requestsHistoryCard.hidden = true;
    successPanel.hidden = true;
    form.hidden = false;
    form.reset();
    discardUnusedChecks();
    chosenFiles.clear();
    checks.clear();
    reviewedRows.clear();
    verifiedStudentInformationFingerprint = "";
    studentVerificationCheckedFingerprint = "";
    const verificationStatus = document.getElementById("studentVerificationStatus");
    document.getElementById("studentVerificationStatusText").textContent = "";
    verificationStatus.className = "form-message";
    clearVerificationMarks();
    verificationStatus.setAttribute("aria-busy", "false");
    document.getElementById("studentVerificationSpinner").hidden = true;
    updateNextButtons();
    documentRows.replaceChildren();
    addDocumentRow(documentId);
    showStep(1);
  }

  // Files that were verified but never submitted are removed from the server.
  function discardUnusedChecks() {
    checks.forEach(state => {
      if (state.checkId) Api.postJson("verify.php", { id: state.checkId }, { action: "discard" }).catch(() => {});
    });
  }

  function closeForm() {
    studentVerificationAttempt++;
    document.getElementById("studentVerificationSpinner").hidden = true;
    document.getElementById("studentVerificationStatus").setAttribute("aria-busy", "false");
    discardUnusedChecks();
    chosenFiles.clear();
    checks.clear();
    requestFormPanel.hidden = true;
    requestsHistoryCard.hidden = false;
    successPanel.hidden = true;
    form.hidden = false;
  }

  function confirmCancelRequest() {
    const dialog = document.createElement("dialog");
    dialog.className = "request-cancel-confirm-dialog";
    dialog.setAttribute("aria-labelledby", "cancelRequestTitle");
    dialog.innerHTML = `
      <div class="logout-confirm-icon" aria-hidden="true"><span class="material-symbol">warning</span></div>
      <h2 id="cancelRequestTitle">Cancel this request?</h2>
      <p>Your request details and uploaded files will be discarded.</p>
      <div class="logout-confirm-actions">
        <button class="request-back-btn" type="button" data-continue-request>Continue request</button>
        <button class="request-next-btn bad" type="button" data-confirm-cancel-request>Yes, cancel</button>
      </div>`;
    document.body.append(dialog);
    dialog.showModal();

    return new Promise(resolve => {
      const finish = confirmed => {
        dialog.close();
        dialog.remove();
        resolve(confirmed);
      };
      dialog.querySelector("[data-continue-request]").addEventListener("click", () => finish(false), { once: true });
      dialog.querySelector("[data-confirm-cancel-request]").addEventListener("click", () => finish(true), { once: true });
      dialog.addEventListener("cancel", event => {
        event.preventDefault();
        finish(false);
      }, { once: true });
      dialog.addEventListener("click", event => {
        if (event.target === dialog) finish(false);
      });
      dialog.querySelector("[data-continue-request]").focus();
    });
  }

  // ---------- Submit ----------
  // One random token per request form. If the same request is sent twice (double click, refresh, bad network) the server returns the first one.
  const newToken = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : "t" + Date.now().toString(36) + Math.random().toString(36).slice(2, 14) + Math.random().toString(36).slice(2, 14);
  let submissionToken = newToken();
  let submitting = false;                 // true while the request is being sent: a second click or Enter does nothing
  form.addEventListener("submit", async event => {
    event.preventDefault();
    // Only the real "Submit Request" button on step 4 may send the request.
    if (activeStep !== 4 || submitting) return;
    if (event.submitter && event.submitter !== submitButton) return;
    const items = chosenDocuments();
    const missing = missingRequirements(items);
    if (missing.length) { showStep(requiresRequirementsStep(items) ? 3 : 2); showMessage("Required document missing. Please upload all required documents before continuing."); return; }

    if (checksRunning > 0) { showMessage("Please wait while your documents are being verified."); return; }
    const checkIds = {};
    items.forEach(item => item.requirements.forEach(req => {
      const state = checks.get(fileKey(item.rowId, req.id));
      if (state && state.checkId && isAccepted(fileKey(item.rowId, req.id))) checkIds[`${item.rowId}:${req.id}`] = state.checkId;
    }));
    const payload = {
      release_method: releaseSelect.value,
      payment_method: paymentSelect.value,
      credential_form: credentialFormData(items),
      student_verification_form: studentVerificationFormData(items),
      submission_token: submissionToken,
      signature_image: needsCredentialForm(items) && window.SignaturePad ? window.SignaturePad.toDataURL() : "",
      items: items.map(item => ({ row: item.rowId, document_id: item.id, quantity: item.quantity, purpose: item.purposeChoice, purpose_other: item.purposeOther })),
      checks: checkIds
    };
    const formData = new FormData();
    formData.append("payload", JSON.stringify(payload));

    submitting = true;
    await Portal.busy(submitButton, "Submitting...", async () => {
      try {
        const result = (await Api.postForm("requests.php", formData, { action: "create" })).request;
        latestRequest = { id: result.id, tracking: result.tracking_no, submittedAt: new Date().toISOString(),
          items: items.map(i => ({ name: i.name, price: i.price, quantity: i.quantity, release: i.release.label })) };
        document.getElementById("requestTrackingNumber").textContent = result.tracking_no;
        document.getElementById("successTotal").textContent = Portal.formatMoney(result.total);
        document.getElementById("requestSuccessText").textContent = result.message + " You will be emailed when your document is ready. Bring a valid ID and pay when you claim it.";
        submissionToken = newToken();                                     // the next request gets its own token
        window.SignaturePad?.clear();
        checks.forEach(state => { state.checkId = null; });             // the files now belong to the request
        chosenFiles.clear();
        checks.clear();
        form.hidden = true;
        successPanel.hidden = false;
        showStep(5);
        renderQrCode();
        window.reloadRequests();
      } catch (error) {
        showMessage(error.message);
        Portal.toast(error.message, "error", "Request not submitted");
        if (error.data && (error.data.missing || error.data.errors)) showStep(requiresRequirementsStep(items) ? 3 : 2);
        if (error.status === 422 && error.message.includes("already have a request for")) showStep(1);
      } finally {
        submitting = false;
      }
    });
  });

  // ---------- QR code (Stage 5) ----------
  // The server saves the QR text the first time and always returns the same one (api/qr.php), so the QR always appears,
  // also after a refresh. showQrCode() (qr.js) draws it and offers "Try again" if something fails.
  async function renderQrCode() {
    const box = document.getElementById("requestTrackingQrCode");
    const status = document.getElementById("requestQrStatus");
    const download = document.getElementById("downloadTrackingQr");
    download.disabled = true;
    status.textContent = "";
    const canvas = await showQrCode(box, latestRequest.id);
    if (canvas) {
      download.disabled = false;
      status.textContent = "Scan this QR code at the registrar office. It is saved with your request.";
    }
  }

  document.getElementById("downloadTrackingQr").addEventListener("click", () => {
    const canvas = document.querySelector("#requestTrackingQrCode canvas");
    if (!canvas || !latestRequest) return;
    const link = document.createElement("a");
    link.download = `${latestRequest.tracking}-QR.png`;
    link.href = canvas.toDataURL("image/png");
    link.click();
  });

  document.getElementById("copyTrackingNumber").addEventListener("click", async event => {
    if (!latestRequest) return;
    try { await navigator.clipboard.writeText(latestRequest.tracking); event.currentTarget.textContent = "Copied"; }
    catch (error) { event.currentTarget.textContent = latestRequest.tracking; }
  });

  // ---------- Events ----------
  Object.values(VERIFICATION_FIELD_IDS).forEach(id => {
    document.getElementById(id).addEventListener("input", event => {
      event.target.removeAttribute("data-verify");
      event.target.removeAttribute("aria-invalid");
    });
  });
  document.getElementById("addDocumentRow").addEventListener("click", () => addDocumentRow());
  document.getElementById("startNewRequestBtn").addEventListener("click", () => startRequest());
  document.getElementById("cancelRequestForm").addEventListener("click", async () => {
    if (await confirmCancelRequest()) { closeForm(); if (landingFlow) backToLanding(); }
  });
  const viewMyRequests = document.getElementById("viewMyRequests");
  if (landingFlow) viewMyRequests.textContent = "Back to Home";
  viewMyRequests.addEventListener("click", () => { closeForm(); if (landingFlow) backToLanding(); });
  document.getElementById("requestAgainBtn")?.addEventListener("click", () => startRequest());
  document.querySelectorAll("[data-next-step]").forEach(b => b.addEventListener("click", () => {
    const items = chosenDocuments();
    if (b.id === "requirementsNextButton" && needsStudentVerificationForm(items)
      && verifiedStudentInformationFingerprint !== studentVerificationFingerprint(items)) {
      verifyStudentInformation();
      return;
    }
    goToStep(Number(b.dataset.nextStep));
  }));
  document.querySelectorAll("[data-previous-step]").forEach(b => b.addEventListener("click", () => showStep(Number(b.dataset.previousStep))));

  documentRows.addEventListener("change", event => { if (event.target.matches(".request-document-select, .request-quantity")) updateSummary(); });
  documentRows.addEventListener("input", event => {
    if (event.target.matches(".request-quantity")) updateSummary();
    if (event.target.matches(".request-purpose")) { event.target.removeAttribute("aria-invalid"); clearMessage(); }
    if (event.target.matches(".request-purpose-other")) { event.target.removeAttribute("aria-invalid"); clearMessage(); }
  });
  documentRows.addEventListener("click", event => {
    const remove = event.target.closest(".request-remove-btn");
    if (!remove) return;
    const row = remove.closest(".request-document-row");
    if (documentRows.children.length === 1) {
      row.querySelector(".request-document-select").value = "";
      row.querySelector(".request-quantity").value = "1";
    } else {
      row.remove();
    }
    updateSummary();
  });

  form.addEventListener("change", async event => {
    const input = event.target.closest(".request-required-file");
    if (input) {
      const key = input.dataset.key;
      const file = input.files[0];
      if (!file) { forgetFile(key, input); redrawUploadBox(key); return; }          // the file dialog was cancelled
      const problem = await fileProblem(file, input);
      if (problem) {
        forgetFile(key, input);
        redrawUploadBox(key);
        Portal.toast(problem.text, "error", problem.title);
        showMessage(problem.text);
        return;
      }
      // The same file cannot be used for two requirements.
      for (const [otherKey, other] of chosenFiles) {
        if (otherKey !== key && other.name === file.name && other.size === file.size && other.lastModified === file.lastModified) {
          input.value = "";
          Portal.toast("You already chose this file for another requirement. Upload a different file.", "error", "Duplicate file");
          return;
        }
      }
      clearMessage();
      verifyFile(key, input, file);
      return;
    }
    const confirm = event.target.closest(".request-requirement-confirm");
    if (confirm) {
      if (confirm.checked) reviewedRows.add(confirm.dataset.rowId); else reviewedRows.delete(confirm.dataset.rowId);
      updateNextButtons();
    }
    if (event.target.closest("#credentialFormSection")) {
      updateCredentialPreview();
      updateNextButtons();
    }
    if (event.target.closest("#studentVerificationFormSection")) {
      refreshStudentVerificationState();
      updateNextButtons();
    }
  });

  form.addEventListener("input", event => {
    if (event.target.closest("#credentialFormSection")) updateCredentialPreview();
    if (event.target.closest("#studentVerificationFormSection")) refreshStudentVerificationState();
    if (event.target.closest("#credentialFormSection, #studentVerificationFormSection")) updateNextButtons();
  });

  // "Remove file" and "Upload Again"
  form.addEventListener("click", event => {
    const remove = event.target.closest("[data-remove]");
    if (remove) {
      const key = remove.dataset.remove;
      const input = form.querySelector(`.request-required-file[data-key="${CSS.escape(key)}"]`);
      forgetFile(key, input);
      redrawUploadBox(key);
      return;
    }
    const retry = event.target.closest("[data-retry]");
    if (retry) {
      const key = retry.dataset.retry;
      retryFile(key, form.querySelector(`.request-required-file[data-key="${CSS.escape(key)}"]`));
      return;
    }
    const again = event.target.closest("[data-again]");
    if (again) {
      const input = document.getElementById(again.dataset.again);
      forgetFile(input.dataset.key, input);
      redrawUploadBox(input.dataset.key);
      input.click();
    }
  });

  form.addEventListener("dragover", event => {
    const zone = event.target.closest(".upload-box");
    if (zone) { event.preventDefault(); zone.classList.add("drag-active"); }
  });
  form.addEventListener("dragleave", event => {
    const zone = event.target.closest(".upload-box");
    if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove("drag-active");
  });
  form.addEventListener("drop", event => {
    const zone = event.target.closest(".upload-box");
    if (!zone) return;
    event.preventDefault();
    zone.classList.remove("drag-active");
    const file = event.dataTransfer?.files?.[0];
    const input = document.getElementById(zone.dataset.inputId);
    if (!file || !input) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });

  async function loadCatalog() {
    try {
      catalog = (await Api.get("documents.php")).documents;
      renderCatalog();
    } catch (error) {
      docGrid.innerHTML = `<p class="requests-empty">${escapeHtml(error.message)}</p>`;
    }
  }

  // The landing page uses short names ("Certificate of Enrollment (COE)") that may differ from the catalog name
  // ("Certificate of Enrollment"), so match exactly first, then without the "(...)" part, then by the words they share.
  function findCatalogDocument(wantedName) {
    const plain = value => String(value).toLowerCase().replace(/\(.*?\)/g, " ").replace(/[^a-z0-9]+/g, " ").trim();
    const words = value => plain(value).split(" ").filter(Boolean);
    const available = catalog.filter(item => !item.pending_request);
    const wanted = plain(wantedName);
    const exact = available.find(item => String(item.name).trim().toLowerCase() === String(wantedName).trim().toLowerCase())
      || available.find(item => plain(item.name) === wanted);
    if (exact) return exact;
    const wantedWords = words(wantedName);
    const candidates = available
      .filter(item => { const have = words(item.name); return wantedWords.every(w => have.includes(w)) || have.every(w => wantedWords.includes(w)); })
      .sort((a, b) => Math.abs(words(a.name).length - wantedWords.length) - Math.abs(words(b.name).length - wantedWords.length));
    return candidates[0] || null;
  }

  // The landing page "Request a Document" form leaves the chosen document here; open the wizard with it picked.
  function startFromLandingPage() {
    let draft = null;
    try { draft = JSON.parse(sessionStorage.getItem("colmRequestDraft") || "null"); sessionStorage.removeItem("colmRequestDraft"); } catch (error) { /* storage may be off */ }
    if (!draft || !draft.document) return;
    const doc = findCatalogDocument(draft.document);
    document.body.classList.remove("dark");          // the landing page flow is always light
    startRequest(doc ? String(doc.id) : "");
    if (window.parent !== window) window.parent.postMessage({ colmReady: true }, window.location.origin);
  }

  document.addEventListener("portal:ready", async event => {
    if (event.detail.role !== "student") { endLandingFlow(); return; }
    try {
      studentAccount = (await Api.get("students.php", { action: "profile" })).student;
      updateSummary();
      await loadCatalog();
      startFromLandingPage();
    } catch (error) { endLandingFlow(); docGrid.innerHTML = `<p class="requests-empty">${escapeHtml(error.message)}</p>`; }
  });
  document.addEventListener("page:change", event => {
    if (event.detail === "Document Catalog") loadCatalog();
  });
})();