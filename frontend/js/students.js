// Admin/registrar: the Students page - list and CSV import.
(function () {
  const body = document.getElementById("studentsBody");
  // The filters work together: School level + Course + Year level
  const levelFilter = document.getElementById("studentLevelFilter");
  const courseFilter = document.getElementById("studentCourseFilter");
  const yearFilter = document.getElementById("studentYearFilter");
  const studentSearchInput = document.getElementById("studentSearchInput");
  let page = 1;
  let loadCounter = 0;                 // an old answer never replaces a newer one
  let legacyCourses = [];              // courses of older students that are not in the school list
  const pager = Portal.makePager(body.closest(".requests-table-wrap"), number => { page = number; loadStudents(); });

  // ---------- List ----------
  async function loadStudents() {
    const params = { action: "list", page };
    if (levelFilter.value) params.level = levelFilter.value;
    if (courseFilter.value) params.course = courseFilter.value;
    if (yearFilter.value) params.year = yearFilter.value;
    const search = studentSearchInput.value.trim() || document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    const myTurn = ++loadCounter;
    try {
      const data = await Api.get("students.php", params);
      if (myTurn !== loadCounter) return;
      const lastPage = Math.max(1, Math.ceil(data.total / data.per_page));
      if (page > lastPage) { page = lastPage; loadStudents(); return; }
      legacyCourses = data.courses;
      pager.render(page, data.total, data.per_page);
      document.getElementById("studentCount").textContent = data.total === 1 ? "1 student found" : `${data.total} students found`;
      body.innerHTML = data.students.length ? data.students.map(s => `
        <tr><td>${escapeHtml(s.student_no || s.username)}</td><td>${escapeHtml(s.full_name)}</td><td>${escapeHtml(s.course || "")}<br><small>${escapeHtml(s.school_level || "")}</small></td>
          <td>${escapeHtml(s.year_label || "")}</td><td>${escapeHtml(s.email || "")}</td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">No students match. Change or reset the filters, or import students from a CSV file.</td></tr>';
    } catch (error) {
      if (myTurn !== loadCounter) return;
      body.innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // ---------- Filters: School level -> Course -> Year level ----------
  // Each list only offers values that exist (from the same school list PHP uses), and a change resets the lists after it.
  async function setupFilters() {
    let levels;
    try { levels = await loadStructure(); } catch (error) { return; }
    const keepValue = (select, hint, items) => {
      const old = select.value;
      fillSelect(select, hint, items, items.some(([value]) => String(value) === old) ? old : "");
      select.disabled = false;
    };
    const chosenLevels = () => levels.filter(l => !levelFilter.value || l.level === levelFilter.value);
    const chosenCourses = () => chosenLevels().flatMap(l => l.courses.filter(c => !courseFilter.value || c.name === courseFilter.value).map(c => ({ ...c, level: l.level })));
    const fillCourses = () => {
      const names = chosenLevels().flatMap(l => l.courses.map(c => c.name));
      legacyCourses.filter(c => !levelFilter.value && !names.includes(c)).forEach(c => names.push(c));
      keepValue(courseFilter, "All courses", [...new Set(names)].map(n => [n, n]));
    };
    const fillYears = () => {
      const years = new Map();
      chosenCourses().forEach(c => c.years.forEach(y => { if (!years.has(y.year)) years.set(y.year, y.label); }));
      keepValue(yearFilter, "All years", [...years].sort((a, b) => a[0] - b[0]));
    };
    if (levelFilter.options.length <= 1) levels.forEach(l => levelFilter.add(new Option(l.level, l.level)));
    fillCourses(); fillYears();
    levelFilter.onchange = () => { courseFilter.value = ""; yearFilter.value = ""; fillCourses(); fillYears(); page = 1; loadStudents(); };
    courseFilter.onchange = () => { yearFilter.value = ""; fillYears(); page = 1; loadStudents(); };
    yearFilter.onchange = () => { page = 1; loadStudents(); };
    document.getElementById("studentFiltersReset").onclick = () => {
      [levelFilter, courseFilter, yearFilter].forEach(box => { box.value = ""; });
      studentSearchInput.value = "";
      document.getElementById("searchInput").value = "";
      fillCourses(); fillYears();
      page = 1;
      loadStudents();
    };
  }

  // ---------- Credentials window (shown once) ----------
  function showCredentials(title, list) {
    const rows = list.map(c => `<tr><td>${escapeHtml(c.full_name)}</td><td><code>${escapeHtml(c.username)}</code></td><td><code>${escapeHtml(c.password)}</code></td></tr>`).join("");
    const modal = Portal.openModal(`
      <h2>${escapeHtml(title)}</h2>
      <div class="notice notice-warning">The default password for all new, imported, or reset student accounts is <strong>Password123!</strong>. Students must choose a new password at the first login.</div>
      <div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Name</th><th>Username (Student ID)</th><th>Default password</th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="detail-buttons"><button class="request-next-btn" type="button" id="downloadCredentials">Download credentials (CSV)</button>
        <button class="request-back-btn" type="button" data-close-modal>Close</button></div>`, true);
    modal.querySelector("#downloadCredentials").addEventListener("click", () => {
      const csv = "full_name,username,default_password\r\n" + list.map(c => [c.full_name, c.username, c.password].map(csvCell).join(",")).join("\r\n") + "\r\n";
      downloadText(csv, "student_credentials.csv");
    });
  }
  const csvCell = value => `"${String(value).replace(/"/g, '""')}"`;
  function downloadText(text, filename) {
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([text], { type: "text/csv;charset=utf-8" }));
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  // The filters include inactive academic items so existing student records remain searchable.
  let structureAll = null;
  async function loadStructure() {
    if (!structureAll) structureAll = (await Api.get("students.php", { action: "structure", all: 1 })).levels;
    return structureAll;
  }
  // Academic Management calls this after the admin changed a list.
  window.clearStructureCache = () => { structureAll = null; };

  // Fills a <select> with options. `items` = list of [value, text]. The first option is the "Select ..." hint.
  function fillSelect(select, hint, items, selectedValue) {
    select.innerHTML = `<option value="">${hint}</option>` + items.map(([value, text]) => `<option value="${escapeHtml(String(value))}">${escapeHtml(text)}</option>`).join("");
    select.value = selectedValue !== undefined && selectedValue !== null ? String(selectedValue) : "";
    select.disabled = items.length === 0;
  }

  // ---------- CSV import ----------
  function importWindow() {
    const modal = Portal.openModal(`
      <h2>Import Students from CSV</h2>
      <p>Each student gets an account. The <strong>Student ID is the username</strong> and the default password is <strong>Password123!</strong> for all students. Students must change it at first login.</p>
      <p><a href="${API_URL}students.php?action=template" id="sampleCsv">Download the sample CSV</a> (columns: student_id, full_name, email, course, year, section, contact_number, status). Student IDs must use 4 digits, a hyphen, then 5 digits (e.g. 1234-56789). Email is required; status is currently_enrolled, alumni, or undergrad. Use an active course, year level and section from Academic Management.</p>
      <input type="file" id="csvFile" accept=".csv,text/csv" aria-label="CSV file">
      <div id="csvResult" class="csv-result"></div>
      <div class="detail-buttons">
        <button class="request-back-btn" type="button" id="csvCheck">Check file</button>
        <button class="request-next-btn" type="button" id="csvImport" disabled>Import students</button>
        <button class="request-back-btn" type="button" data-close-modal>Cancel</button>
      </div>`, true);
    const fileInput = modal.querySelector("#csvFile");
    const result = modal.querySelector("#csvResult");
    const importButton = modal.querySelector("#csvImport");

    const send = dryRun => {
      const form = new FormData();
      form.append("csv", fileInput.files[0]);
      if (dryRun) form.append("dry_run", "1");
      return Api.postForm("students.php", form, { action: "import" });
    };
    const failedTable = failed => failed.length ? `
      <h3>Rows with problems (${failed.length}) — these are NOT imported</h3>
      <div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Line</th><th>Student ID</th><th>Name</th><th>Problem</th></tr></thead><tbody>
      ${failed.map(f => `<tr><td>${f.line}</td><td>${escapeHtml(f.student_id)}</td><td>${escapeHtml(f.full_name)}</td><td>${f.errors.map(escapeHtml).join("<br>")}</td></tr>`).join("")}</tbody></table></div>` : "";

    fileInput.addEventListener("change", () => { importButton.disabled = true; result.innerHTML = ""; });

    modal.querySelector("#csvCheck").addEventListener("click", async event => {
      if (!fileInput.files[0]) { Portal.toast("Choose a CSV file first.", "error"); return; }
      await Portal.busy(event.currentTarget, "Checking...", async () => {
        try {
          const data = await send(true);
          result.innerHTML = `<div class="notice ${data.failed.length ? "notice-warning" : "notice-ok"}"><strong>${data.valid_count}</strong> student(s) are ready to import${data.failed.length ? `, <strong>${data.failed.length}</strong> row(s) have problems` : ""}.</div>
            ${data.preview.length ? `<h3>Preview</h3><div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th></tr></thead><tbody>
            ${data.preview.map(p => `<tr><td>${escapeHtml(p.student_id)}</td><td>${escapeHtml(p.full_name)}</td><td>${escapeHtml(p.course)}</td><td>${escapeHtml(String(p.year))}</td><td>${escapeHtml(p.section)}</td></tr>`).join("")}</tbody></table></div>` : ""}
            ${failedTable(data.failed)}`;
          importButton.disabled = data.valid_count === 0;
        } catch (error) { result.innerHTML = `<div class="notice notice-warning">${escapeHtml(error.message)}</div>`; importButton.disabled = true; }
      });
    });

    importButton.addEventListener("click", async () => {
      await Portal.busy(importButton, "Importing...", async () => {
        try {
          const data = await send(false);
          loadStudents();
          const credentials = data.imported.map(i => ({ full_name: i.full_name, username: i.username, password: i.password }));
          if (credentials.length) {
            showCredentials(`${credentials.length} student account(s) created`, credentials);
            const emailFailures = data.imported.filter(i => !i.email_sent).length;
            if (emailFailures) Portal.toast(`${emailFailures} account notification email(s) could not be sent. Check SMTP configuration and arrange secure delivery of the credentials.`, "warning");
            if (data.failed.length) {
              const box = document.querySelector("#modalBackdrop .modal-body");
              box.insertAdjacentHTML("beforeend", failedTable(data.failed));
            }
          } else {
            result.innerHTML = `<div class="notice notice-warning">No student was imported.</div>${failedTable(data.failed)}`;
          }
        } catch (error) { Portal.toast(error.message, "error"); }
      });
    });
  }

  // ---------- Buttons ----------
  document.getElementById("importCsvBtn").addEventListener("click", importWindow);

  studentSearchInput.addEventListener("input", Portal.debounce(() => {
    if (Portal.currentPage !== "Students") return;
    page = 1;
    loadStudents();
  }));
  document.getElementById("searchInput").addEventListener("input", Portal.debounce(() => {
    if (Portal.currentPage !== "Students") return;
    page = 1;
    loadStudents();
  }));
  document.addEventListener("page:change", event => { if (event.detail === "Students") { setupFilters(); loadStudents(); } });
})();
