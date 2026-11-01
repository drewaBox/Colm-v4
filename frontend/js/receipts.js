// Admin/registrar: review student-uploaded payment receipts.
(function () {
  const body = document.getElementById("paymentReceiptsBody");
  if (!body) return;

  // The numbers the student typed from the Official Receipt + the warnings found by the security check.
  function receiptDetails(receipt) {
    const flags = receipt.security_flags || [];
    const amountOk = receipt.amount_paid !== null && Math.abs(Number(receipt.amount_paid) - Number(receipt.total_amount)) < 0.005;
    return `<strong>OR No. ${escapeHtml(receipt.official_receipt_no || "—")}</strong><br>
      <small>Paid: ${receipt.amount_paid === null ? "—" : Portal.formatMoney(receipt.amount_paid)}${amountOk ? "" : " (does not match total)"}<br>
      Date: ${escapeHtml(receipt.date_paid || "—")}</small>
      ${flags.length
        ? `<div class="notice notice-warning receipt-flags"><strong>Check carefully:</strong><ul>${flags.map(flag => `<li>${escapeHtml(flag)}</li>`).join("")}</ul></div>`
        : '<div class="receipt-ok"><small>No security warnings.</small></div>'}
      <small class="receipt-reminder">Compare this OR No. with the school's receipt records before verifying.</small>`;
  }

  async function loadReceipts() {
    try {
      const receipts = (await Api.get("payments.php", { action: "receipts" })).receipts;
      body.innerHTML = receipts.length ? receipts.map(receipt => `
        <tr>
          <td>${escapeHtml(receipt.tracking_no)}</td>
          <td>${escapeHtml(receipt.full_name)}<br><small>${escapeHtml(receipt.student_no || "")}</small></td>
          <td>${escapeHtml(receipt.course || "")}</td>
          <td>${Portal.formatMoney(receipt.total_amount)}</td>
          <td>${escapeHtml(receipt.payment_method)}</td>
          <td>${receiptDetails(receipt)}</td>
          <td><a href="${API_URL}payments.php?action=receipt_file&id=${receipt.request_id}&inline=1" target="_blank" rel="noopener">Preview</a>
            · <a href="${API_URL}payments.php?action=receipt_file&id=${receipt.request_id}">Download</a></td>
          <td>${escapeHtml(Portal.formatDate(receipt.uploaded_at, true))}</td>
          <td><textarea class="review-remarks" data-receipt-remarks="${receipt.request_id}" maxlength="500" placeholder="Required if rejecting"></textarea>
            <button class="request-table-action" type="button" data-receipt-decision="Verified" data-request-id="${receipt.request_id}">Verify</button>
            <button class="request-table-action danger" type="button" data-receipt-decision="Rejected" data-request-id="${receipt.request_id}">Reject</button></td>
        </tr>`).join("") : '<tr><td colspan="9" class="requests-empty">No payment receipts are waiting for review.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="9" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  body.addEventListener("click", async event => {
    const button = event.target.closest("[data-receipt-decision]");
    if (!button) return;
    const id = Number(button.dataset.requestId);
    const remarks = body.querySelector(`[data-receipt-remarks="${id}"]`).value.trim();
    if (button.dataset.receiptDecision === "Rejected" && !remarks) {
      Portal.toast("Explain why the receipt was rejected.", "error");
      return;
    }
    if (button.dataset.receiptDecision === "Verified" && body.querySelector(`[data-receipt-remarks="${id}"]`).closest("tr").querySelector(".receipt-flags")
      && !confirm("This receipt has security warnings. Did you compare the OR No. with the school's receipt records and are you sure it is genuine?")) return;
    await Portal.busy(button, "Saving...", async () => {
      try {
        const result = await Api.postJson("payments.php", {
          request_id: id, decision: button.dataset.receiptDecision, remarks
        }, { action: "review_receipt" });
        Portal.toast(result.message, "success");
        loadReceipts();
        window.reloadRequests?.();
      } catch (error) { Portal.toast(error.message, "error"); }
    });
  });

  document.addEventListener("page:change", event => {
    if (event.detail === "Payment Receipts") loadReceipts();
  });
})();