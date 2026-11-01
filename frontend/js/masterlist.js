// Master Lists (admin and registrar): import one semester, see what will be archived, search active and archived validation records (api/masterlist.php).
(function () {
  const form = document.getElementById("masterImportForm");
  if (!form) return;
  const plan = document.getElementById("masterPlan");
  const importButton = document.getElementById("masterImportBtn");
  const filterIds = ["mfScope", "mfYear", "mfSemester", "mfNo", "mfName", "mfStatus", "mfFrom", "mfTo"];
  let page = 1, total = 0, perPage = 25, stage = "check";       // stage: check (first press) -> import (second press, after the plan was shown)

  function formData(extra) {
    const data = new FormData();
    data.append("csv", document.getElementById("masterFile").files[0]);
    data.append("academic_year", document.getElementById("masterYear").value.trim());
    data.append("semester", document.getElementById("masterSemester").value);
    Object.entries(extra).forEach(([k, v]) => data.append(k, v));
    return data;
  }
  function resetStage() { stage = "check"; importButton.textContent = "Check file"; plan.hidden = true; }
  form.addEventListener("change", resetStage);

  form.addEventListener("submit", async event => {
    event.preventDefault();
    await Portal.busy(importButton, stage === "check" ? "Checking..." : "Importing...", async () => {
      try {
        if (stage === "check") {
          const r = await Api.postForm("masterlist.php", formData({ dry_run: 1 }), { action: "import" });
          const c = r.counts;
          plan.hidden = false;
          plan.innerHTML = `<strong>${escapeHtml(r.period)}</strong>: ${r.rows} students (validated ${c.VALIDATED}, mismatch ${c.MISMATCH}, not registered ${c.NOT_REGISTERED}, invalid ${c.INVALID}).`
            + (r.skipped.length ? `<br>Skipped rows: ${r.skipped.length}.` : "")
            + (r.will_archive.length ? `<br><strong>Will be archived:</strong> ${r.will_archive.map(a => escapeHtml(a.label)).join(", ")}.` : "<br>Nothing will be archived.")
            + "<br>Press the button again to import.";
          stage = "import";
          importButton.textContent = r.will_archive.length ? "Import and archive" : "Import";
        } else {
          const r = await Api.postForm("masterlist.php", formData({ confirm_archive: 1 }), { action: "import" });
          Portal.toast(`${r.period} imported (${r.rows} records).${r.will_archive.length ? " Older list archived." : ""}`, "success");
          form.reset(); resetStage(); loadImports(); page = 1; loadRecords();
        }
      } catch (error) {
        Portal.toast(error.message, "error", "Master list");
        resetStage();
      }
    });
    if (importButton.textContent.startsWith("Check") || importButton.textContent.startsWith("Import")) importButton.disabled = false;
  });

  async function loadImports() {
    const body = document.getElementById("masterImports");
    try {
      const rows = (await Api.get("masterlist.php", { action: "imports" })).imports;
      body.innerHTML = rows.length ? rows.map(i => `<tr><td>${escapeHtml(i.academic_year)}</td><td>${escapeHtml(i.semester_label)}</td><td>${i.row_count}</td>
        <td>${i.status === "ACTIVE" ? "Active" : "Archived"}</td><td>${escapeHtml(i.imported_at)}</td><td>${escapeHtml(i.archived_at || "—")}</td></tr>`).join("")
        : '<tr><td colspan="6" class="requests-empty">No master list imported yet.</td></tr>';
    } catch (error) { body.innerHTML = `<tr><td colspan="6" class="requests-empty">${escapeHtml(error.message)}</td></tr>`; }
  }

  async function loadRecords() {
    const body = document.getElementById("masterRecords");
    const value = id => document.getElementById(id).value.trim();
    try {
      const r = await Api.get("masterlist.php", { action: "records", page, scope: value("mfScope"), academic_year: value("mfYear"), semester: value("mfSemester"),
        student_no: value("mfNo"), name: value("mfName"), status: value("mfStatus"), archived_from: value("mfFrom"), archived_to: value("mfTo") });
      total = r.total; perPage = r.per_page;
      body.innerHTML = r.records.length ? r.records.map(x => `<tr><td>${escapeHtml(x.student_no)}</td><td>${escapeHtml(x.student_name)}</td>
        <td>${escapeHtml([x.course, x.year_level, x.section].filter(Boolean).join(" / ") || "—")}</td>
        <td>${escapeHtml(x.validation_status)}${x.validation_note ? `<br><small>${escapeHtml(x.validation_note)}</small>` : ""}</td>
        <td>${escapeHtml(x.academic_year)} · ${escapeHtml(x.semester_label)}</td><td>${x.list_status === "ACTIVE" ? "Active" : "Archived"}</td><td>${escapeHtml(x.archived_at || "—")}</td></tr>`).join("")
        : '<tr><td colspan="7" class="requests-empty">No records found.</td></tr>';
      const pages = Math.max(1, Math.ceil(total / perPage));
      document.getElementById("mfPage").textContent = `Page ${page} of ${pages} (${total} records)`;
      document.getElementById("mfPrev").disabled = page <= 1;
      document.getElementById("mfNext").disabled = page >= pages;
    } catch (error) { body.innerHTML = `<tr><td colspan="7" class="requests-empty">${escapeHtml(error.message)}</td></tr>`; }
  }

  filterIds.forEach(id => document.getElementById(id).addEventListener(id === "mfNo" || id === "mfName" || id === "mfYear" ? "input" : "change",
    Portal.debounce(() => { page = 1; loadRecords(); })));
  document.getElementById("mfPrev").addEventListener("click", () => { page--; loadRecords(); });
  document.getElementById("mfNext").addEventListener("click", () => { page++; loadRecords(); });
  document.addEventListener("page:change", event => { if (event.detail === "Master Lists") { loadImports(); loadRecords(); } });
})();
