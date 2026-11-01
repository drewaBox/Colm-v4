// The drawing box for the electronic signature (credential request form). Works with mouse, finger and pen.
// window.SignaturePad.isEmpty() / toDataURL() / clear(). The server checks the picture again; this is only the drawing part.
(function () {
  const canvas = document.getElementById("signaturePad");
  if (!canvas) return;
  const context = canvas.getContext("2d");
  canvas.width = 480; canvas.height = 160;        // fixed drawing size; CSS only scales it
  let drawing = false, strokes = 0;

  function setup() {
    context.lineWidth = 2.5; context.lineCap = "round"; context.lineJoin = "round"; context.strokeStyle = "#111";
  }
  function point(event) {
    const box = canvas.getBoundingClientRect();
    return { x: (event.clientX - box.left) * canvas.width / box.width, y: (event.clientY - box.top) * canvas.height / box.height };
  }
  canvas.addEventListener("pointerdown", event => {
    event.preventDefault();
    drawing = true; setup();
    canvas.setPointerCapture(event.pointerId);
    const p = point(event);
    context.beginPath(); context.moveTo(p.x, p.y); context.lineTo(p.x + 0.1, p.y + 0.1); context.stroke();
  });
  canvas.addEventListener("pointermove", event => {
    if (!drawing) return;
    event.preventDefault();
    const p = point(event);
    context.lineTo(p.x, p.y); context.stroke();
  });
  const stop = () => { if (drawing) { drawing = false; strokes++; } };
  canvas.addEventListener("pointerup", stop);
  canvas.addEventListener("pointercancel", stop);

  window.SignaturePad = {
    isEmpty: () => strokes === 0,
    toDataURL: () => canvas.toDataURL("image/png"),
    clear: () => { context.clearRect(0, 0, canvas.width, canvas.height); strokes = 0; }
  };
  document.getElementById("signatureClear")?.addEventListener("click", () => window.SignaturePad.clear());
})();
