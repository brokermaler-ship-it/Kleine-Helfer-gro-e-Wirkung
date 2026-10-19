"use strict";

const { Room, RoomEvent, Track } = LivekitClient;

const state = {
  pilotId: "",
  module: null,
  modules: [],
  room: null,
  preScore: null,
  protocolMarkdown: "",
};

const $ = (id) => document.getElementById(id);

const STATUS_TEXT = {
  connecting: "Verbinde …",
  initializing: "Tutor startet …",
  listening: "Ich höre zu",
  thinking: "Denke nach …",
  speaking: "Tutor spricht",
  idle: "Bereit",
};

function show(view) {
  for (const el of document.querySelectorAll(".view")) el.hidden = el.id !== view;
}

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "class") node.className = v;
    else if (k.startsWith("data-")) node.setAttribute(k, v);
    else node[k] = v;
  }
  for (const c of children) node.append(c);
  return node;
}

async function api(path, options = {}) {
  const res = await fetch(path, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  if (!res.ok) throw new Error(`${res.status}: ${await res.text()}`);
  return res.json();
}

// ---------- Start ----------

async function initStart() {
  state.modules = await api("/api/modules");
  const select = $("module-select");
  for (const m of state.modules) select.append(el("option", { value: m.id, textContent: m.title }));
  select.addEventListener("change", renderModuleInfo);
  renderModuleInfo();

  $("start-form").addEventListener("submit", (e) => {
    e.preventDefault();
    if (!readStartForm()) return;
    startQuiz("vorher");
  });
  $("retention-only").addEventListener("click", () => {
    if (!readStartForm()) return;
    startQuiz("spaeter");
  });
}

function renderModuleInfo() {
  const m = state.modules.find((x) => x.id === $("module-select").value);
  const info = $("module-info");
  info.replaceChildren();
  if (!m) return;
  info.append(`Etwa ${m.estimated_minutes} Minuten · Stand ${m.version}`);
  const list = el("ul");
  for (const o of m.objectives) list.append(el("li", { textContent: o.text }));
  info.append(list);
}

function readStartForm() {
  const form = $("start-form");
  if (!form.reportValidity()) return false;
  state.pilotId = $("pilot-id").value.trim();
  state.module = state.modules.find((x) => x.id === $("module-select").value);
  return true;
}

// ---------- Wissenscheck ----------

const QUIZ_COPY = {
  vorher: ["Wissenscheck vorher", "Beantworte die Fragen so gut du kannst. Raten ist erlaubt, es geht um den Vergleich mit nachher."],
  nachher: ["Wissenscheck nachher", "Dieselben Fragen wie vorhin. Danach siehst du die Auflösung."],
  spaeter: ["Wissenscheck (Wiederholung)", "Einige Zeit nach der Lerneinheit: Was ist hängen geblieben?"],
};

async function startQuiz(phase) {
  const questions = await api(`/api/modules/${state.module.id}/quiz`);
  const [title, lead] = QUIZ_COPY[phase];
  $("quiz-title").textContent = `${title}: ${state.module.title}`;
  $("quiz-lead").textContent = lead;
  $("quiz-result").hidden = true;

  const form = $("quiz-form");
  form.replaceChildren();
  for (const q of questions) {
    const fs = el("fieldset", { class: "question", "data-qid": q.id }, el("legend", { textContent: q.question }));
    q.options.forEach((opt, i) => {
      fs.append(el("label", {}, el("input", { type: "radio", name: q.id, value: String(i), required: true }), opt));
    });
    form.append(fs);
  }
  const submit = el("button", { type: "submit", class: "primary", textContent: "Antworten abschicken" });
  form.append(el("div", { class: "row" }, submit));
  form.onsubmit = async (e) => {
    e.preventDefault();
    if (!form.reportValidity()) return;
    submit.disabled = true;
    const answers = {};
    for (const q of questions) answers[q.id] = Number(new FormData(form).get(q.id));
    const result = await api(`/api/modules/${state.module.id}/quiz`, {
      method: "POST",
      body: JSON.stringify({ pilot_id: state.pilotId, phase, answers }),
    });
    onQuizResult(phase, result);
  };
  show("view-quiz");
  window.scrollTo(0, 0);
}

function onQuizResult(phase, result) {
  if (phase === "vorher") {
    state.preScore = result.score;
    startSession();
    return;
  }
  // Auflösung anzeigen
  for (const r of result.results) {
    const fs = document.querySelector(`fieldset[data-qid="${r.id}"]`);
    fs.classList.add(r.correct ? "correct" : "wrong");
    for (const input of fs.querySelectorAll("input")) input.disabled = true;
    const right = fs.querySelectorAll("label")[r.correct_index];
    right.append(el("strong", { textContent: "  ✓ richtig" }));
    if (r.explanation) fs.append(el("p", { class: "explanation", textContent: r.explanation }));
  }
  const box = $("quiz-result");
  box.replaceChildren(el("div", { class: "score", textContent: `${result.score} von ${result.total} richtig` }));
  if (phase === "nachher" && state.preScore !== null) {
    box.append(el("p", { class: "muted", textContent: `Vorher: ${state.preScore} von ${result.total}.` }));
  }
  box.append(el("p", { class: "muted", textContent: "Danke! Du kannst das Fenster jetzt schließen." }));
  box.hidden = false;
  box.scrollIntoView({ behavior: "smooth" });
}

// ---------- Gespräch ----------

function setStatus(s) {
  $("status").dataset.state = s;
  $("status-text").textContent = STATUS_TEXT[s] ?? s;
}

function renderObjectives(objectives) {
  const list = $("objectives");
  list.replaceChildren();
  for (const o of objectives) list.append(el("li", { textContent: o.text, "data-status": o.status }));
}

function addKeyPoint(text) {
  const list = $("key-points");
  list.querySelector(".empty")?.remove();
  list.append(el("li", { textContent: text }));
}

function showSource(src) {
  $("source").replaceChildren(el("strong", { textContent: src.title }), src.snippet);
}

function showProtocol(markdown) {
  state.protocolMarkdown = markdown;
  $("protocol-content").replaceChildren(...renderMarkdown(markdown));
  $("protocol-dialog").showModal();
}

/** Minimaler, sicherer Markdown-Renderer für das Lernprotokoll (Überschriften, Listen, fett). */
function renderMarkdown(md) {
  const nodes = [];
  let list = null;
  const inline = (text) => {
    const frag = document.createDocumentFragment();
    text.split(/(\*\*[^*]+\*\*)/).forEach((part) => {
      if (part.startsWith("**") && part.endsWith("**")) frag.append(el("strong", { textContent: part.slice(2, -2) }));
      else if (part) frag.append(part);
    });
    return frag;
  };
  for (const line of md.split("\n")) {
    if (line.startsWith("- ")) {
      if (!list) nodes.push((list = el("ul")));
      list.append(el("li", {}, inline(line.slice(2))));
      continue;
    }
    list = null;
    if (line.startsWith("# ")) nodes.push(el("h1", { textContent: line.slice(2) }));
    else if (line.startsWith("## ")) nodes.push(el("h2", { textContent: line.slice(3) }));
    else if (line.trim()) nodes.push(el("p", {}, inline(line)));
  }
  return nodes;
}

const transcriptBubbles = new Map();

function updateTranscript(key, who, text) {
  let bubble = transcriptBubbles.get(key);
  if (!bubble) {
    bubble = el("div", { class: `bubble ${who === "Du" ? "user" : "agent"}` }, el("span", { class: "who", textContent: who }), el("span"));
    transcriptBubbles.set(key, bubble);
    $("transcript").append(bubble);
  }
  bubble.lastChild.textContent = text;
  $("transcript").scrollTop = $("transcript").scrollHeight;
}

const DATA_HANDLERS = {
  agent_state: (p) => setStatus(p.status),
  agent_progress: (p) => renderObjectives(p.objectives),
  agent_key_point: (p) => addKeyPoint(p.text),
  agent_sources: (p) => p.sources.forEach(showSource),
  session_protocol: (p) => showProtocol(p.markdown),
};

async function startSession() {
  show("view-session");
  setStatus("connecting");
  renderObjectives(state.module.objectives.map((o) => ({ ...o, status: "offen" })));

  const { url, token } = await api("/api/session", {
    method: "POST",
    body: JSON.stringify({ module_id: state.module.id, pilot_id: state.pilotId }),
  });

  const room = new Room({ adaptiveStream: true, dynacast: true });
  state.room = room;
  const decoder = new TextDecoder();

  room.on(RoomEvent.DataReceived, (payload, _participant, _kind, topic) => {
    const handler = DATA_HANDLERS[topic];
    if (!handler) return;
    try {
      handler(JSON.parse(decoder.decode(payload)));
    } catch (err) {
      console.warn("Ungültiges Event", topic, err);
    }
  });

  room.on(RoomEvent.TrackSubscribed, (track) => {
    if (track.kind === Track.Kind.Audio) document.body.append(track.attach());
  });

  room.on(RoomEvent.Disconnected, () => setStatus("idle"));

  room.registerTextStreamHandler("lk.transcription", async (reader, participantInfo) => {
    const who = participantInfo.identity === room.localParticipant.identity ? "Du" : "Tutor";
    const key = reader.info.attributes?.["lk.segment_id"] ?? reader.info.id;
    let text = "";
    for await (const chunk of reader) {
      text += chunk;
      updateTranscript(key, who, text);
    }
  });

  await room.connect(url, token);
  await room.startAudio();
  await room.localParticipant.setMicrophoneEnabled(true);
  setStatus("initializing");
}

async function endSession() {
  if (state.room) await state.room.disconnect();
  state.room = null;
}

function initSessionControls() {
  $("mute").addEventListener("click", async () => {
    const lp = state.room?.localParticipant;
    if (!lp) return;
    const enable = !lp.isMicrophoneEnabled;
    await lp.setMicrophoneEnabled(enable);
    $("mute").textContent = enable ? "Mikrofon aus" : "Mikrofon an";
  });
  $("end").addEventListener("click", async () => {
    await endSession();
    if (!$("protocol-dialog").open) startQuiz("nachher");
  });
  $("protocol-continue").addEventListener("click", async () => {
    $("protocol-dialog").close();
    await endSession();
    startQuiz("nachher");
  });
  $("protocol-download").addEventListener("click", () => {
    const blob = new Blob([state.protocolMarkdown], { type: "text/markdown" });
    const a = el("a", { href: URL.createObjectURL(blob), download: `lernprotokoll-${state.module.id}.md` });
    a.click();
    URL.revokeObjectURL(a.href);
  });
}

initSessionControls();
initStart().catch((err) => {
  $("module-info").textContent = `Module konnten nicht geladen werden: ${err.message}`;
});
