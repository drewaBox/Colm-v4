// Request list, dashboard numbers and the request details window. Used by every role.
(function () {
  const requestsBody = document.getElementById("requestsBody");
  // Filters above the request table (the same ids exist on the student page and on the staff page)
  const statusFilter = document.getElementById("statusFilter");
  const paymentFilter = document.getElementById("paymentFilter");
  const documentFilter = document.getElementById("documentFilter");
  const dateFrom = document.getElementById("dateFrom");
  const dateTo = document.getElementById("dateTo");
  const allRequestsSearch = document.getElementById("allRequestsSearchInput");
  let user = null;
  let page = 1;
  let loadCounter = 0;          // so an old answer can never replace a newer one when the filters change quickly
  const pager = Portal.makePager(requestsBody.closest(".requests-table-wrap"), number => { page = number; loadRequests(); });

  const STATUS_LABELS = {
    SUBMITTED: "Submitted", AI_VERIFYING: "Document Checking", FOR_REVIEW: "For Admin Review",
    NEEDS_CORRECTION: "Needs Correction", PROCESSING: "Processing", READY: "Ready for Release",
    COMPLETED: "Completed", REJECTED: "Rejected", CANCELLED: "Cancelled"
  };
  window.STATUS_LABELS = STATUS_LABELS;

  const AI_LABELS = { PASS: "AI: No problem found", REVIEW: "AI: Not sure", FAIL: "AI: Declined", UNAVAILABLE: "AI unavailable" };
  // The school uses three words: Pending, Verified, Declined.
  const REVIEW_LABELS = { Pending: "Pending", Verified: "Verified", Rejected: "Declined", Resubmit: "Declined" };

  const statusPill = status => `<span class="status-pill status-${escapeHtml(status.toLowerCase())}">${escapeHtml(STATUS_LABELS[status] || status)}</span>`;
  window.statusPill = statusPill;

  // Students see four simple words (Pending, Under Verification, Approved, Rejected); staff see the detailed status.
  const PORTAL_CLASS = { pending: "submitted", under_verification: "for_review", needs_correction: "needs_correction", approved: "ready", completed: "completed", rejected: "rejected", cancelled: "rejected" };
  const portalPill = portal => `<span class="status-pill status-${PORTAL_CLASS[portal.key] || "submitted"}">${escapeHtml(portal.label)}</span>`;
  const requestPill = request => (user && user.role === "student" && request.portal) ? portalPill(request.portal) : statusPill(request.status);
  const ROUTE_SHORT = { REGISTRAR: "Registrar route", AUTO: "AI-assisted" };
  const VERDICT_SHORT = { PASS: "AI passed", REVIEW: "AI flagged", NEEDS_CORRECTION: "AI: needs correction" };

  // The release date is typed by the Registrar. Until then the student sees "To be announced".
  function releaseDateLabel(request) {
    if (request.status === "COMPLETED") return request.completed_at ? `Released ${Portal.formatDate(request.completed_at)}` : "Released";
    if (request.status === "CANCELLED") return "Cancelled";
    if (request.expected_release_date) return `Expected ${Portal.formatDate(request.expected_release_date)}`;
    return "To be announced";
  }

  // ---------- Dashboard numbers ----------
  async function loadStats() {
    try {
      const stats = (await Api.get("requests.php", { action: "stats" })).stats;
      const isStudent = user.role === "student";
      const second = isStudent ? stats.processing : stats.for_review;
      const values = { statTotal: stats.total, statProcessing: second, statPending: stats.pending_payment, statReady: stats.ready, statCompleted: stats.completed };
      Object.entries(values).forEach(([id, value]) => { document.getElementById(id).textContent = value; });
      if (!isStudent) {
        document.getElementById("statTotalLabel").textContent = "All Requests";
        document.getElementById("statProcessingLabel").textContent = "Waiting for Review";
        document.getElementById("statReadyLabel").textContent = "Ready for Release";
        document.getElementById("statCompletedLabel").textContent = "Completed";
      }
      const empty = document.getElementById("dashboardEmpty");
      if (empty && isStudent) empty.hidden = stats.total > 0;
    } catch (error) { /* the dashboard just keeps its old numbers */ }
  }

  // ---------- Request table ----------
  function actionButtons(request) {
    const view = (label) => `<button class="request-table-action" type="button" data-view="${request.id}">${label}</button>`;
    if (user.role === "student") return view(request.status === "NEEDS_CORRECTION" ? "Fix documents" : "View");
    return view("View");
  }

  function renderRows(requests) {
    const showOverdue = user.role === "student";
    const showAction = showOverdue;
    const columnCount = showOverdue ? 10 : 8;
    if (!requests.length) {
      requestsBody.innerHTML = `<tr><td colspan="${columnCount}" class="requests-empty">No requests found.</td></tr>`;
    } else {
      requestsBody.innerHTML = requests.map(request => `
        <tr${showAction ? "" : ` class="request-open-row" data-request-row="${request.id}" tabindex="0" aria-label="Open request ${escapeHtml(request.tracking_no)}"`}>
          <td>${escapeHtml(request.tracking_no)}</td>
          <td>${escapeHtml(request.full_name)}</td>
          <td>${request.items.map(item => `${escapeHtml(item.name)} <span class="request-history-copies">× ${item.quantity}</span>`).join("<br>")}</td>
          <td>${Portal.formatMoney(request.total_amount)}</td>
          <td>${escapeHtml(releaseDateLabel(request))}</td>
          <td>${escapeHtml(request.payment_status)}</td>
          <td>${escapeHtml(request.payment_method)}</td>
          <td>${requestPill(request)}${user.role !== "student" && request.verification_route ? `<br><small>${escapeHtml(ROUTE_SHORT[request.verification_route] || "")} · ${escapeHtml(VERDICT_SHORT[request.verification_verdict] || "")}</small>` : ""}${request.softcopy_label ? `<br><small>${escapeHtml(request.softcopy_label)}</small>` : ""}</td>
          ${showOverdue ? `<td>${request.is_overdue ? '<span class="overdue-flag">Overdue</span>' : ""}</td>` : ""}
          ${showAction ? `<td>${actionButtons(request)}</td>` : ""}
        </tr>`).join("");
    }
    document.dispatchEvent(new CustomEvent("table:rendered"));
  }

  function requestListParams(pageNumber, options = {}) {
    const params = { action: "list", page: pageNumber };
    if (statusFilter && statusFilter.value) params.status = statusFilter.value;
    if (paymentFilter && paymentFilter.value) params.payment = paymentFilter.value;
    if (documentFilter && documentFilter.value) params.document = documentFilter.value;
    if (options.releaseRange) {
      params.released_from = options.releaseRange.from;
      params.released_to = options.releaseRange.to;
    } else {
      if (dateFrom && dateFrom.value) params.from = dateFrom.value;
      if (dateTo && dateTo.value) params.to = dateTo.value;
    }
    const dedicatedSearch = Portal.currentPage === "All Requests" && allRequestsSearch
      ? allRequestsSearch.value.trim()
      : "";
    const search = dedicatedSearch || document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    if (dedicatedSearch) params.request_search = "1";
    return params;
  }

  async function loadRequests() {
    const myTurn = ++loadCounter;
    try {
      const data = await Api.get("requests.php", requestListParams(page));
      if (myTurn !== loadCounter) return;                         // a newer search already started
      // If the filter result has fewer pages than the page we are on, go back to the last page.
      const lastPage = Math.max(1, Math.ceil(data.total / data.per_page));
      if (page > lastPage) { page = lastPage; loadRequests(); return; }
      renderRows(data.requests);
      pager.render(page, data.total, data.per_page);
      const count = document.getElementById("requestCount");
      if (count) count.textContent = data.total === 1 ? "1 request found" : `${data.total} requests found`;
    } catch (error) {
      if (myTurn !== loadCounter) return;
      requestsBody.innerHTML = `<tr><td colspan="${user.role === "student" ? 10 : 8}" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
      pager.render(1, 0, 1);
    }
  }

  function openExportOptions() {
    const modal = Portal.openModal(`
      <h2>Export requests</h2>
      <p>Choose a release date range. Only requests released during this period will be included.</p>
      <form class="student-form" id="requestExportForm">
        <div class="request-two-column">
          <label>From<input class="request-text-input" id="requestExportFrom" type="date" required></label>
          <label>To<input class="request-text-input" id="requestExportTo" type="date" required></label>
        </div>
        <div class="detail-buttons">
          <button class="request-next-btn" type="submit">Export CSV</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button>
        </div>
      </form>`, true);
    const form = modal.querySelector("#requestExportForm");
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const from = modal.querySelector("#requestExportFrom").value;
      const to = modal.querySelector("#requestExportTo").value;
      if (from > to) {
        Portal.toast("The From date must be on or before the To date.", "error");
        return;
      }
      await exportRequests({ from, to }, `document_requests_${from}_to_${to}.csv`, form.querySelector('[type="submit"]'));
    });
  }

  async function exportRequests(releaseRange, filename, button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Exporting...";
    try {
      const filters = requestListParams(1, { releaseRange });
      const firstPage = await Api.get("requests.php", filters);
      const requests = [...firstPage.requests];
      const pageCount = Math.ceil(firstPage.total / firstPage.per_page);
      for (let pageNumber = 2; pageNumber <= pageCount; pageNumber += 1) {
        const data = await Api.get("requests.php", { ...filters, page: pageNumber });
        requests.push(...data.requests);
      }

      const csvCell = value => {
        let text = String(value ?? "");
        if (/^[\t\r ]*[=+\-@]/.test(text)) text = "'" + text;
        return `"${text.replace(/"/g, '""')}"`;
      };
      const rows = [
        ["Tracking #", "Requester", "Document", "Amount", "Date Release", "Payment", "Payment method", "Status"],
        ...requests.map(request => [
          request.tracking_no,
          request.full_name,
          request.items.map(item => `${item.name} x ${item.quantity}`).join("; "),
          request.total_amount,
          releaseDateLabel(request),
          request.payment_status,
          request.payment_method,
          STATUS_LABELS[request.status] || request.status
        ])
      ];
      const csv = "\uFEFF" + rows.map(row => row.map(csvCell).join(",")).join("\r\n");
      const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 0);
      Portal.closeModal();
    } catch (error) {
      Portal.toast(error.message, "error", "Export failed");
    } finally {
      button.textContent = originalText;
      button.disabled = false;
    }
  }

  // Other files call this after something changed.
  window.reloadRequests = function () {
    loadRequests();
    loadStats();
    window.refreshNotifications?.();
    document.dispatchEvent(new CustomEvent("requests:changed"));
  };
  window.loadStats = loadStats;

  // ---------- Request details window ----------
  async function openRequestDetail(id) {
    try {
      const data = await Api.get("requests.php", { action: "detail", id });
      if (id !== fixesRequestId) { discardFixes(); fixesRequestId = id; }
      let modal = document.querySelector("#modalBackdrop .modal-body");
      if (!modal || Number(modal.dataset.requestId) !== id) modal = Portal.openModal("", true).querySelector(".modal-body");
      modal.dataset.requestId = id;
      modal.innerHTML = detailHtml(data);
      const qrBox = modal.querySelector("#detailQr");
      if (qrBox) showQrCode(qrBox, id);         // the QR is saved on the server, so it is always the same code
    } catch (error) {
      Portal.toast(error.message, "error");
    }
  }
  window.openRequestDetail = openRequestDetail;

  function fileSize(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(bytes / 1024)) + " KB"; }
  const fileUrl = (id, inline) => `${API_URL}file.php?id=${id}${inline ? "&inline=1" : ""}`;

  function aiHtml(file, isRegistrar) {
    if (!file.ai) return '<span class="ai-pill ai-none">AI not run yet</span>';
    const ai = file.ai;
    let html = `<span class="ai-pill ai-${escapeHtml(ai.status.toLowerCase())}">${escapeHtml(AI_LABELS[ai.status])}</span>
      <small class="ai-reason">${escapeHtml(ai.reason || "")}</small>`;
    if (isRegistrar && ai.status !== "UNAVAILABLE" && ai.extracted_text !== undefined) {
      html += `<details class="ai-details"><summary>Details</summary>
        <p>Name on document: <strong>${escapeHtml(ai.name_on_document || "not found")}</strong>${ai.name_match_score !== null ? ` (match ${ai.name_match_score}%)` : ""}</p>
        <p>Confidence: ${ai.confidence !== null ? Math.round(ai.confidence * 100) + "%" : "not given"} · Model: ${escapeHtml(ai.model || "")} · ${escapeHtml(Portal.formatDate(ai.created_at, true))}</p>
        <p class="ai-text">${escapeHtml(ai.extracted_text || "No text was extracted.")}</p></details>`;
    }
    if (isRegistrar && ai.error_message) html += `<small class="ai-error">${escapeHtml(ai.error_message)}</small>`;
    return html;
  }

  // Documents a student uploads again after a correction request: "itemId:requirementId" -> { state, checkId, message, file }
  const fixes = new Map();
  let fixesRequestId = 0;
  let fixesRunning = 0;

  function discardFixes() {
    fixes.forEach(f => { if (f.checkId) Api.postJson("verify.php", { id: f.checkId }, { action: "discard" }).catch(() => {}); });
    fixes.clear();
    fixesRunning = 0;
  }

  function redrawFixBox(key) {
    const box = document.querySelector(`#modalBackdrop .upload-box[data-fix-key="${CSS.escape(key)}"]`);
    if (!box) return;
    const f = fixes.get(key);
    box.innerHTML = uploadBoxHtml(f, f && f.file, box.dataset.inputId, box.dataset.types.toUpperCase().split(",").join(", "), box.dataset.maxMb, key);
  }

  function updateSendButton() {
    const button = document.querySelector("#modalBackdrop [data-action='send-corrections']");
    if (!button) return;
    button.disabled = fixesRunning > 0;
    button.title = fixesRunning > 0 ? "Please wait while your documents are being verified." : "";
  }

  // "Try Again": check the stored file again (or send the file again if it was never stored).
  async function retryFix(key, input) {
    const old = fixes.get(key);
    if (!old || !old.file) return;
    if (!old.checkId) { verifyFix(key, input, old.file); return; }
    fixes.set(key, { state: "checking", checkId: old.checkId, file: old.file });
    redrawFixBox(key);
    fixesRunning++;
    updateSendButton();
    try {
      const state = stateFromCheck(await retryUpload(old.checkId));
      fixes.set(key, { ...state, file: old.file });
      Portal.toast(state.message, state.state === "verified" ? "success" : "error", "Verification");
    } catch (error) {
      fixes.set(key, { ...stateFromError(error), checkId: old.checkId, file: old.file });
    } finally {
      fixesRunning = Math.max(0, fixesRunning - 1);
      updateSendButton();
      redrawFixBox(key);
    }
  }

  async function verifyFix(key, input, file) {
    const old = fixes.get(key);
    fixes.set(key, { state: "checking", file });
    redrawFixBox(key);
    fixesRunning++;
    updateSendButton();
    try {
      const result = await verifyUpload(file, input.dataset.reqId, old && old.checkId);
      if (!fixes.has(key) || fixes.get(key).file !== file) {
        if (result.id) Api.postJson("verify.php", { id: result.id }, { action: "discard" }).catch(() => {});
        return;
      }
      const state = stateFromCheck(result);
      fixes.set(key, { ...state, file });
      const types = { verified: ["success", "Document verified"], declined: ["error", "Verification declined"], needs_correction: ["error", "Needs correction"], pending: ["warning", "Verification not completed"] };
      Portal.toast(state.message, types[state.state][0], types[state.state][1]);
    } catch (error) {
      if (!fixes.has(key) || fixes.get(key).file !== file) return;
      const state = stateFromError(error);
      fixes.set(key, { ...state, file });
      Portal.toast(state.message, "error", "Verification not completed");
    } finally {
      fixesRunning = Math.max(0, fixesRunning - 1);
      updateSendButton();
      redrawFixBox(key);
    }
  }

  // Automatic check of the online form: the result of the AI verification (the form is compared with the student's account).
  function formAutoCheckHtml(request) {
    const verdict = request.verification?.verdict;
    if (verdict === "PASS") return '<span class="ai-pill ai-pass">Verified</span><span class="ai-reason">Matches the student\'s account records.</span>';
    if (verdict === "REVIEW") return '<span class="ai-pill ai-review">Needs review</span><span class="ai-reason">The AI flagged something in the form.</span>';
    if (verdict === "NEEDS_CORRECTION") return '<span class="ai-pill ai-fail">Needs correction</span>';
    return "—";
  }

  function requirementRow(request, item, req) {
    const isRegistrar = isStaff(user.role);
    const canSeeFiles = request.can_see_files;
    const studentFixes = user.role === "student" && request.status === "NEEDS_CORRECTION" && req.needs_fix;
    let fileCell, aiCell = "", reviewCell = "";

    if (req.form_complete) {
      return `<tr><td><strong>${escapeHtml(req.name)}</strong><br><small>${escapeHtml(req.description || "")}</small></td>
        <td>Completed online and shown above</td>${isRegistrar ? `<td>${formAutoCheckHtml(request)}</td>` : ""}<td><span class="review-pill review-verified">Submitted</span></td></tr>`;
    }
    if (!canSeeFiles) {
      return `<tr><td><strong>${escapeHtml(req.name)}</strong></td><td colspan="3" class="muted">Uploaded documents are only visible to the admin/registrar and the student.</td></tr>`;
    }
    const key = `${item.id}:${req.id}`;
    if (req.file) {
      const f = req.file;
      fileCell = `<span class="file-name">${escapeHtml(f.original_name)}</span> <small>(${fileSize(f.size_bytes)})</small><br>
        <a href="${fileUrl(f.id, true)}" target="_blank" rel="noopener">Preview</a> ·
        <a href="${fileUrl(f.id, false)}">Download</a>`;
      aiCell = aiHtml(f, isRegistrar);
      reviewCell = `<span class="review-pill review-${escapeHtml(f.review_status.toLowerCase())}">${escapeHtml(REVIEW_LABELS[f.review_status])}</span>`;
      if (f.review_remarks) reviewCell += `<small class="review-remark">${["Rejected", "Resubmit"].includes(f.review_status) ? "Declined — " : ""}${escapeHtml(f.review_remarks)}</small>`;
      if (isRegistrar && ["FOR_REVIEW", "NEEDS_CORRECTION"].includes(request.status)) {
        reviewCell += `<div class="review-controls" data-file-id="${f.id}">
          <input type="text" class="review-remarks" maxlength="500" placeholder="Reason (required to decline)" aria-label="Remark for ${escapeHtml(req.name)}">
          <button type="button" class="mini-btn ok" data-review="Verified">Approve</button>
          <button type="button" class="mini-btn bad" data-review="Rejected">Decline</button>
          <button type="button" class="mini-btn" data-run-ai="${f.id}">Verify again (AI)</button></div>`;
      }
    } else {
      fileCell = '<span class="missing-file">Required document missing</span>';
    }
    if (studentFixes) {
      const inputId = `fix-${item.id}-${req.id}`;
      const f = fixes.get(key);
      fileCell += `<input type="file" class="request-required-file fix-file" id="${inputId}" data-key="${key}" data-item-id="${item.id}" data-req-id="${req.id}"
          data-max-mb="${req.max_size_mb}" data-types="${escapeHtml(req.allowed_types)}" accept=".${req.allowed_types.split(",").join(",.")}"
          aria-label="New file for ${escapeHtml(req.name)}">
        <div class="upload-box" data-fix-key="${key}" data-input-id="${inputId}" data-types="${escapeHtml(req.allowed_types)}" data-max-mb="${req.max_size_mb}" aria-live="polite">${uploadBoxHtml(f, f && f.file, inputId, req.allowed_types.toUpperCase().split(",").join(", "), req.max_size_mb, key)}</div>`;
    }
    // Students see the file and its status only. The automatic check column is for the admin/registrar.
    return `<tr class="${studentFixes ? "needs-fix" : ""}"><td><strong>${escapeHtml(req.name)}</strong><br><small>${escapeHtml(req.description || "")}</small></td>
      <td>${fileCell}</td>${isRegistrar ? `<td>${aiCell}</td>` : ""}<td>${reviewCell}</td></tr>`;
  }

  // Expected release date, payment when claiming and reinstate (the server checks the role again).
  function staffReleaseControls(request) {
    const s = request.status;
    const isAdmin = user.role === "admin";
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    let html = "";
    if (["FOR_REVIEW", "NEEDS_CORRECTION", "PROCESSING", "READY"].includes(s)) {
      html += `<div class="release-control"><label>Expected release date
        <input class="request-text-input" type="date" id="releaseDateInput" value="${escapeHtml(request.expected_release_date || "")}" min="${today}"></label>
        <button type="button" class="request-next-btn" data-save-release-date>Save date</button></div>`;
    }
    if (s === "READY" && request.payment_term === "UPON_RELEASE" && request.payment_status === "Unpaid" && Number(request.total_amount) > 0) {
      html += `<div class="release-control"><label>Official Receipt No. (optional)
        <input class="request-text-input" type="text" id="paymentOrInput" maxlength="40" autocomplete="off"></label>
        <button type="button" class="request-next-btn" data-confirm-payment>Payment received (${Portal.formatMoney(Number(request.total_amount))})</button></div>`;
    }
    if (isAdmin && s === "CANCELLED") {
      html += `<div class="release-control"><label>Reason for reinstating
        <input class="request-text-input" type="text" id="adminReasonInput" maxlength="255"></label>
        <button type="button" class="request-next-btn warn" data-reinstate>Reinstate request</button></div>`;
    }
    if (request.payment) {
      const p = request.payment;
      html += `<p class="request-date-note">Payment ${escapeHtml(p.receipt_no)} · ${Portal.formatMoney(Number(p.amount))} · ${escapeHtml(p.paid_at)} · received by ${escapeHtml(p.received_by || "—")}${p.refunded_at ? ` · REFUNDED ${escapeHtml(p.refunded_at)} (${escapeHtml(p.refund_reason || "")})` : ""}</p>`;
    }
    return html ? `<section class="release-controls"><h3>Release and payment</h3>${html}</section>` : "";
  }

  function actionBar(request, items) {
    if (user.role === "student") {
      const fixButton = request.status === "NEEDS_CORRECTION" && request.needs_fix
        ? '<button type="button" class="request-next-btn" data-action="send-corrections">Send corrected documents</button>' : "";
      return `${fixButton ? `<div class="detail-actions">${fixButton}</div>` : ""}`;
    }
    if (!isStaff(user.role)) return "";
    const releaseControls = staffReleaseControls(request);

    const s = request.status;
    const remarks = '<textarea class="detail-remarks" rows="2" maxlength="500" placeholder="Message to the student (required for correction or rejection)"></textarea>';
    const button = (status, label, css = "") => `<button type="button" class="request-next-btn ${css}" data-set-status="${status}">${label}</button>`;
    const paymentPending = !paymentCleared(request);
    // One button: "Ready to Release". It works once the Official Receipt passed the automatic check (or was approved).
    const readyButton = paymentPending
      ? `<button type="button" class="request-next-btn" disabled title="${request.payment_receipt ? "Review the Official Receipt in the Payment Receipts page first." : "The student has not uploaded the Official Receipt yet."}">Ready to Release</button>`
      : button("READY", "Ready to Release");
    let buttons = "";
    if (s === "FOR_REVIEW") {
      buttons = readyButton
        + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "PROCESSING") {
      buttons = readyButton
        + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "READY") {
      const unpaid = request.payment_status !== "Paid" && Number(request.total_amount) > 0;
      buttons = unpaid
        ? '<button type="button" class="request-next-btn" disabled title="Record the payment first.">Complete (released)</button>'
        : button("COMPLETED", "Complete (released)");
    } else if (s === "NEEDS_CORRECTION") {
      buttons = button("REJECTED", "Reject request", "bad");
    } else {
      return releaseControls;
    }
    return `${releaseControls}<div class="detail-actions">${s === "READY" ? "" : remarks}<div class="detail-buttons">${buttons}</div></div>`;
  }

  // Paid, or the uploaded receipt is verified / passed the automatic security checks (no warnings).
  function paymentCleared(request) {
    if (request.payment_term === "UPON_RELEASE") return true;      // paid when the student claims the document
    if (Number(request.total_amount) <= 0 || request.payment_status === "Paid") return true;
    const receipt = request.payment_receipt;
    return !!receipt && (receipt.status === "Verified" || (receipt.status === "Pending" && receipt.clean));
  }

  // Students: no receipt upload any more. They pay at the Registrar's Office when they claim the document.
  function payOnReleaseHtml(request) {
    const money = Portal.formatMoney(Number(request.total_amount));
    if (request.status === "CANCELLED") return '<div class="notice notice-warning">This request was cancelled because it was not paid or claimed within 14 days. Please submit a new request.</div>';
    if (["REJECTED", "COMPLETED"].includes(request.status)) return "";
    if (request.payment_status === "Paid") return '<div class="notice notice-ok">Payment received. Thank you.</div>';
    if (request.payment_status === "Refunded") return '<div class="notice notice-warning">Your payment was refunded.</div>';
    if (request.status === "READY") {
      return `<div class="notice notice-warning"><strong>Ready for pickup.</strong> Pay ${money} at the Registrar's Office when you claim it${request.claim_by ? `, on or before <strong>${escapeHtml(Portal.formatDate(request.claim_by))}</strong>` : ""}. After that the request is cancelled.</div>`;
    }
    return `<div class="notice notice-ok">You pay ${money} when you claim your document. No receipt upload is needed.</div>`;
  }

  function paymentReceiptHtml(request) {
    if (user.role === "student" && request.payment_term === "UPON_RELEASE") return Number(request.total_amount) > 0 ? payOnReleaseHtml(request) : "";
    if (user.role !== "student" || Number(request.total_amount) <= 0) return "";
    if (["REJECTED", "COMPLETED"].includes(request.status)) return "";
    const receipt = request.payment_receipt;
    if (request.payment_status === "Paid") {
      return '<div class="notice notice-ok">Payment confirmed. Keep and bring the hard copy of your payment receipt when your document is released.</div>';
    }
    if (receipt?.status === "Pending") {
      return `<div class="notice notice-warning">Your payment receipt is waiting for Registrar review. Bring the hard copy when your document is released. <a href="${API_URL}payments.php?action=receipt_file&id=${request.id}&inline=1" target="_blank" rel="noopener">View uploaded receipt</a></div>`;
    }
    const rejected = receipt?.status === "Rejected"
      ? `<div class="notice notice-warning"><strong>Receipt needs correction:</strong> ${escapeHtml(receipt.remarks || "Upload a clearer receipt.")}</div>` : "";
    const requestDate = String(request.created_at || "").slice(0, 10);
    return `${rejected}<form id="paymentReceiptForm" class="info-card receipt-upload-form" enctype="multipart/form-data">
      <input type="hidden" name="request_id" value="${request.id}">
      <h3>Upload payment receipt</h3>
      <div class="receipt-upload-grid">
        <label>Official Receipt No.
          <input class="request-text-input" type="text" name="official_receipt_no" maxlength="15" autocomplete="off" inputmode="numeric" value="COLM-" pattern="COLM-\\d{4}-\\d{5}" title="Format: COLM-0000-00000" required>
        </label>
        <label>Amount paid (PHP)
          <input class="request-text-input is-locked" type="text" name="amount_paid" value="${Number(request.total_amount).toFixed(2)}" readonly aria-readonly="true" tabindex="-1">
        </label>
        <label>Date of request
          <input class="request-text-input is-locked" type="date" name="date_paid" value="${escapeHtml(requestDate)}" readonly aria-readonly="true" tabindex="-1">
        </label>
        <label>Receipt file (PDF, JPG, or PNG; up to 5 MB)
          <input class="request-text-input" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" required>
        </label>
      </div>
      <small class="receipt-upload-note">Type the Official Receipt No. exactly as printed (format COLM-0000-00000). It is checked against the picture and the school's records. Keep the original receipt and bring it when collecting your document. A receipt that was edited or generated will be rejected.</small>
      <div class="receipt-upload-actions"><button class="request-next-btn" type="submit">Upload receipt</button></div>
    </form>`;
  }

  function detailHtml(data) {
    const r = data.request;
    const note = (r.registrar_remarks && ["NEEDS_CORRECTION", "REJECTED"].includes(r.status))
      ? `<div class="notice notice-warning"><strong>Message from the school:</strong> ${escapeHtml(r.registrar_remarks)}</div>` : "";
    const items = data.items.map(item => `
      <section class="detail-item">
        <h3>${escapeHtml(item.name)} <small>× ${item.quantity} · ${Portal.formatMoney(item.unit_price * item.quantity)}</small></h3>
        ${item.purpose ? `<p class="request-date-note"><strong>Purpose:</strong> ${escapeHtml(item.purpose)}</p>` : ""}
        <div class="requests-table-wrap"><table class="detail-table">
          <thead><tr><th>Requirement</th><th>Uploaded file</th>${isStaff(user.role) ? "<th>Automatic check</th>" : ""}<th>Status</th></tr></thead>
          <tbody>${item.requirements.map(req => requirementRow(r, item, req)).join("")}</tbody>
        </table></div>
        ${window.softcopyBlockHtml ? window.softcopyBlockHtml(r, item) : ""}
      </section>`).join("");
    const legacyCredentialInformation = !r.credential_form && r.academic_year
      ? `<div><dt>Academic year / semester</dt><dd>${escapeHtml(r.academic_year)} · ${escapeHtml(r.semester || "")}</dd></div><div><dt>Program</dt><dd>${escapeHtml(r.program || "")}</dd></div>`
      : !r.credential_form && r.year_graduated ? `<div><dt>Year graduated</dt><dd>${escapeHtml(String(r.year_graduated))}</dd></div>` : "";
    const credentialFormDetails = r.credential_form ? (() => {
      const form = r.credential_form;
      const field = (label, value) => `<div><dt>${label}</dt><dd>${escapeHtml(value || "—")}</dd></div>`;
      const purpose = form.purpose === "Others" ? `Others: ${form.other_purpose || ""}` : form.purpose;
      const graduation = form.graduated === "yes" ? `Yes${form.graduation_date ? ` · ${form.graduation_date}` : ""}` : `No${form.last_attended_school_year ? ` · Last attended ${form.last_attended_school_year}` : ""}`;
      return `<section class="credential-request-details"><h3>Credential Request Form</h3><dl class="detail-grid">
        ${field("Requested documents", (form.requested_documents || []).join(", "))}
        ${field("Name", [form.first_name, form.middle_name, form.last_name].filter(Boolean).join(" "))}
        ${field("Address", form.address)}${field("Birthdate", form.birthdate)}${field("Gender", form.gender)}
        ${field("Student no.", form.student_no)}${field("Grade level", form.grade_level)}${field("Strand", form.strand)}${field("Course", form.course)}
        ${field("Graduation", graduation)}${field("Elementary school / year graduated", form.elementary_school)}
        ${field("Junior high school / year graduated", form.junior_high_school)}${field("Senior high school / year graduated", form.senior_high_school)}
        ${field("Telephone no.", form.telephone)}${field("Mobile no.", form.mobile)}${field("Credential purpose", purpose)}
        <div><dt>Signature</dt><dd>${escapeHtml(form.signature || "—")}${r.signature ? `<br><img class="signature-view" src="${API_URL}signature.php?request_id=${r.id}" alt="Drawn signature">` : ""}</dd></div>${field("Notes", form.notes)}
      </dl></section>`;
    })() : "";
    const studentVerificationDetails = r.student_verification_form ? (() => {
      const form = r.student_verification_form;
      const field = (label, value) => `<div><dt>${label}</dt><dd>${escapeHtml(value || "—")}</dd></div>`;
      return `<section class="credential-request-details"><h3>Student Verification Form</h3><dl class="detail-grid">
        ${field("Student ID", form.student_id)}
        ${field("Student Name", form.student_name)}
        ${field("Course/Program", form.course_program)}
        ${field("Academic Year", form.academic_year)}
        ${field("Grade Level", form.grade_level)}
      </dl></section>`;
    })() : "";
    return `
      <h2>Request ${escapeHtml(r.tracking_no)} ${requestPill(r)}</h2>
      <dl class="detail-grid">
        <div><dt>Requester</dt><dd>${escapeHtml(r.full_name)}${r.student_no ? " (" + escapeHtml(r.student_no) + ")" : ""}</dd></div>
        ${data.items.some(item => item.purpose) ? "" : `<div><dt>Purpose</dt><dd>${escapeHtml(r.purpose)}</dd></div>`}
        <div><dt>Document format</dt><dd>${escapeHtml(formatLabel(r.release_method))} · ${escapeHtml(releaseDateLabel(r))}</dd></div>
        <div><dt>Payment</dt><dd>${Portal.formatMoney(Number(r.total_amount))} · ${escapeHtml(r.payment_method)} · ${escapeHtml(r.payment_status)}${r.payment_term === "UPON_RELEASE" ? " · pay on release" : ""}</dd></div>
        ${r.claim_by && isStaff(user.role) ? `<div><dt>Claim by</dt><dd>${escapeHtml(Portal.formatDate(r.claim_by))}</dd></div>` : ""}
        ${legacyCredentialInformation}
      </dl>
      ${credentialFormDetails}
      ${studentVerificationDetails}
      ${note}${paymentReceiptHtml(r)}
      ${items}
      ${user.role === "student" ? `<h3>QR code</h3><div class="qr-box" id="detailQr"></div>` : ""}
      ${actionBar(r, data.items)}`;
  }

  // ---------- Buttons inside the details window ----------
  document.addEventListener("click", async event => {
    const modalBody = event.target.closest("#modalBackdrop .modal-body");
    if (!modalBody) return;
    const requestId = Number(modalBody.dataset.requestId);
    const target = event.target;
    const refresh = async () => { await openRequestDetail(requestId); window.reloadRequests(); };

    const reviewButton = target.closest("[data-review]");
    if (reviewButton) {
      const box = reviewButton.closest(".review-controls");
      await Portal.busy(reviewButton, "Saving...", async () => {
        await Api.postJson("requests.php", {
          file_id: Number(box.dataset.fileId), decision: reviewButton.dataset.review,
          remarks: box.querySelector(".review-remarks").value
        }, { action: "review_file" });
        await refresh();
      });
      return;
    }

    const aiButton = target.closest("[data-run-ai]");
    if (aiButton) {
      await Portal.busy(aiButton, "Running AI...", async () => {
        const body = { id: requestId, file_id: Number(aiButton.dataset.runAi) };
        const result = await Api.postJson("requests.php", body, { action: "run_ai" });
        Portal.toast(result.summary, result.counts.UNAVAILABLE ? "warning" : "success");
        await refresh();
      });
      return;
    }

    // Release date, payment and reinstate: one small helper (the server checks the role and the rules again).
    const releaseCall = async (button, busyText, action, body) => {
      await Portal.busy(button, busyText, async () => {
        const result = await Api.postJson("release.php", { id: requestId, ...body }, { action });
        Portal.toast(result.message || "Saved.", "success");
        await refresh();
      });
    };
    const dateButton = target.closest("[data-save-release-date]");
    if (dateButton) {
      const date = modalBody.querySelector("#releaseDateInput")?.value || "";
      if (!date) { Portal.toast("Choose the expected release date first.", "warning"); return; }
      await releaseCall(dateButton, "Saving...", "set_release_date", { date });
      return;
    }
    const payButton = target.closest("[data-confirm-payment]");
    if (payButton) {
      if (!window.confirm("Confirm that the student paid the full amount?")) return;
      await releaseCall(payButton, "Saving...", "confirm_payment", { official_receipt_no: modalBody.querySelector("#paymentOrInput")?.value || "" });
      return;
    }
    const reinstateButton = target.closest("[data-reinstate]");
    if (reinstateButton) {
      await releaseCall(reinstateButton, "Saving...", "reinstate", { reason: modalBody.querySelector("#adminReasonInput")?.value || "" });
      return;
    }

    const statusButton = target.closest("[data-set-status]");
    if (statusButton) {
      const remarks = modalBody.querySelector(".detail-remarks")?.value || "";
      await Portal.busy(statusButton, "Saving...", async () => {
        const result = await Api.postJson("requests.php", { id: requestId, status: statusButton.dataset.setStatus, remarks }, { action: "set_status" });
        Portal.toast(result.message || "Request updated.", "success");
        await refresh();
      });
      return;
    }

    const actionButton = target.closest("[data-action]");
    if (actionButton?.dataset.action === "send-corrections") {
      const inputs = [...modalBody.querySelectorAll(".fix-file")];
      if (fixesRunning > 0) { Portal.toast("Please wait while your documents are being verified.", "warning"); return; }
      const checkIds = {};
      for (const input of inputs) {
        const f = fixes.get(input.dataset.key);
        if (f && f.state !== "verified" && f.state !== "checking") { Portal.toast("A document is not verified. Please fix it (Upload Again / Try Again) first.", "error", "Not verified"); return; }
        if (!f || !isVerifiedState(f)) { Portal.toast("Please upload all documents that need correction.", "error", "Required document missing"); return; }
        checkIds[input.dataset.key] = f.checkId;
      }
      await Portal.busy(actionButton, "Sending...", async () => {
        const form = new FormData();
        form.append("payload", JSON.stringify({ checks: checkIds }));
        const result = await Api.postForm("requests.php", form, { action: "resubmit", id: requestId });
        fixes.forEach(f => { f.checkId = null; });          // the files now belong to the request
        fixes.clear();
        Portal.toast(result.message, "success", "Documents sent");
        await refresh();
      });
    }
  });

  // Official Receipt No.: "COLM-" is always there; the student only types the 9 digits (the dash is added automatically).
  document.addEventListener("input", event => {
    const field = event.target.closest?.('#paymentReceiptForm [name="official_receipt_no"]');
    if (!field) return;
    const digits = field.value.toUpperCase().replace(/^COLM-?/, "").replace(/\D/g, "").slice(0, 9);
    field.value = "COLM-" + digits.slice(0, 4) + (digits.length > 4 ? "-" + digits.slice(4) : "");
    field.setCustomValidity(/^COLM-\d{4}-\d{5}$/.test(field.value) ? "" : "Use the format COLM-0000-00000.");
  });

  // Keep the cursor after "COLM-" so the prefix can't be edited.
  function keepOrCursorAfterPrefix(event) {
    const field = event.target.closest?.('#paymentReceiptForm [name="official_receipt_no"]');
    if (field && field.selectionStart < 5) field.setSelectionRange(field.value.length, field.value.length);
  }
  document.addEventListener("focusin", keepOrCursorAfterPrefix);
  document.addEventListener("click", keepOrCursorAfterPrefix);

  // Upload button: Upload receipt -> Uploading... -> Checking receipt... -> Receipt verified / Sent for review (or Try again).
  const RECEIPT_BUTTON_IDLE = "Upload receipt";
  function setReceiptButton(button, text, state) {
    button.textContent = text;
    button.className = "request-next-btn" + (state ? " " + state : "");
  }
  // After a failed try, typing a new number or choosing another file puts the button back to normal.
  function resetReceiptButtonAfterError(event) {
    const form = event.target.closest?.("#paymentReceiptForm");
    const button = form && form.querySelector("[type=submit]");
    if (button && button.classList.contains("bad")) setReceiptButton(button, RECEIPT_BUTTON_IDLE, "");
  }
  document.addEventListener("input", resetReceiptButtonAfterError);
  document.addEventListener("change", resetReceiptButtonAfterError);

  document.addEventListener("submit", async event => {
    const form = event.target.closest("#modalBackdrop #paymentReceiptForm");
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector("[type=submit]");
    if (!button || button.disabled) return;
    button.disabled = true;
    setReceiptButton(button, "Uploading...", "busy");
    try {
      const result = await Api.postFormWithProgress("payments.php", new FormData(form), { action: "upload_receipt" },
        () => setReceiptButton(button, "Checking receipt...", "busy"));          // the file arrived; the server is now reading it
      const passed = result.check === "passed";
      setReceiptButton(button, passed ? "Receipt verified \u2713" : "Sent for review", passed ? "ok" : "warn");
      Portal.toast(result.message, passed ? "success" : "warning");
      await new Promise(resolve => setTimeout(resolve, 1800));                  // let the student read the result
      await openRequestDetail(Number(form.querySelector("[name=request_id]").value));
      window.reloadRequests();
    } catch (error) {
      if (button.isConnected) { setReceiptButton(button, "Try again", "bad"); button.disabled = false; }
      Portal.toast(error.message, "error", "Receipt not uploaded");
    }
  });

  // Closing the window without sending: the verified files that were not sent are removed from the server.
  const closeModalBase = Portal.closeModal;
  Portal.closeModal = function () { discardFixes(); closeModalBase(); };

  // ---------- Student: choosing a new file for a declined document ----------
  document.addEventListener("change", async event => {
    const input = event.target.closest("#modalBackdrop .fix-file");
    if (!input) return;
    const key = input.dataset.key;
    const file = input.files[0];
    if (!file) { fixes.delete(key); redrawFixBox(key); return; }
    const problem = await fileProblem(file, input);
    if (problem) {
      fixes.delete(key);
      input.value = "";
      redrawFixBox(key);
      Portal.toast(problem.text, "error", problem.title);
      return;
    }
    verifyFix(key, input, file);
  });
  document.addEventListener("click", event => {
    const box = event.target.closest("#modalBackdrop .upload-box[data-fix-key]");
    if (!box) return;
    const remove = event.target.closest("[data-remove]");
    const again = event.target.closest("[data-again]");
    const retry = event.target.closest("[data-retry]");
    const key = box.dataset.fixKey;
    if (retry) { retryFix(key, document.getElementById(box.dataset.inputId)); return; }
    if (!remove && !again) return;
    const input = document.getElementById(box.dataset.inputId);
    const old = fixes.get(key);
    if (old && old.checkId) Api.postJson("verify.php", { id: old.checkId }, { action: "discard" }).catch(() => {});
    fixes.delete(key);
    input.value = "";
    redrawFixBox(key);
    if (again) input.click();
  });

  // ---------- Table buttons ----------
  requestsBody.addEventListener("click", async event => {
    const view = event.target.closest("[data-view]");
    if (view) { openRequestDetail(Number(view.dataset.view)); return; }
    const row = event.target.closest("[data-request-row]");
    if (row && user && isStaff(user.role)) openRequestDetail(Number(row.dataset.requestRow));
  });
  requestsBody.addEventListener("keydown", event => {
    const row = event.target.closest("[data-request-row]");
    if (!row || (event.key !== "Enter" && event.key !== " ")) return;
    event.preventDefault();
    openRequestDetail(Number(row.dataset.requestRow));
  });
  document.getElementById("exportRequestsBtn")?.addEventListener("click", openExportOptions);

  // Fill the lists of the filters, then reload the table whenever a filter changes (all filters work together).
  if (statusFilter) {
    statusFilter.innerHTML += Object.entries(STATUS_LABELS).map(([value, label]) => `<option value="${value}">${label}</option>`).join("");
  }
  async function fillDocumentFilter() {
    if (!documentFilter || documentFilter.options.length > 1) return;
    try {
      (await Api.get("documents.php")).documents.forEach(doc => documentFilter.add(new Option(doc.name, doc.id)));
    } catch (error) { /* the filter simply stays on "All documents" */ }
  }
  const filterBoxes = [statusFilter, paymentFilter, documentFilter, dateFrom, dateTo].filter(Boolean);
  filterBoxes.forEach(box => box.addEventListener("change", () => {
    if (dateFrom && dateTo && dateFrom.value && dateTo.value && dateFrom.value > dateTo.value) {
      Portal.toast("The \"From\" date is after the \"To\" date.", "warning", "Check the dates");
      return;
    }
    page = 1;
    loadRequests();
  }));
  document.getElementById("filtersReset")?.addEventListener("click", () => {
    filterBoxes.forEach(box => { box.value = ""; });
    if (allRequestsSearch) allRequestsSearch.value = "";
    document.getElementById("searchInput").value = "";
    applySearch();
    page = 1;
    loadRequests();
  });

  // The search box also searches on the server, so older requests can be found too.
  document.getElementById("searchInput").addEventListener("input", Portal.debounce(() => {
    if (!["My Requests", "All Requests"].includes(Portal.currentPage)) return;
    page = 1;
    loadRequests();
  }));
  allRequestsSearch?.addEventListener("input", Portal.debounce(() => {
    if (Portal.currentPage !== "All Requests") return;
    page = 1;
    loadRequests();
  }));

  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (["My Requests", "All Requests"].includes(event.detail)) { fillDocumentFilter(); loadRequests(); }
    if (["Dashboard", "Overview & KPIs"].includes(event.detail)) loadStats();
  });

  document.addEventListener("portal:ready", event => {
    user = event.detail;
    loadStats();
    loadRequests();
    setInterval(() => { if (!document.hidden) loadStats(); }, 60000);
  });
})();