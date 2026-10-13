/* Embeddable chat widget. Usage:
   <script src="chat-widget.js" data-api="https://your-backend/chat" data-title="Ask us"></script>
   No API keys here: the browser only talks to YOUR backend. */
(function () {
  var s = document.currentScript;
  var API = s.getAttribute("data-api") || "http://localhost:8000/chat";
  var TITLE = s.getAttribute("data-title") || "Chat with us";
  var sid = "s" + Math.random().toString(36).slice(2, 12);

  var css = document.createElement("style");
  css.textContent =
    ".cw-btn{position:fixed;right:20px;bottom:20px;width:56px;height:56px;border-radius:50%;border:0;background:#1F7A9E;color:#fff;font-size:24px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);z-index:9999}" +
    ".cw-box{position:fixed;right:20px;bottom:90px;width:340px;max-width:calc(100vw - 40px);height:460px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font:14px/1.4 system-ui,sans-serif;z-index:9999}" +
    ".cw-box.open{display:flex}.cw-h{background:#1B3A57;color:#fff;padding:12px 14px;font-weight:600}" +
    ".cw-m{flex:1;overflow:auto;padding:12px;background:#f5f7fa}.cw-b{max-width:80%;padding:8px 11px;margin:6px 0;border-radius:10px;white-space:pre-wrap}" +
    ".cw-u{background:#1F7A9E;color:#fff;margin-left:auto}.cw-a{background:#fff;color:#1b1b1b;border:1px solid #e1e5ea}" +
    ".cw-f{display:flex;border-top:1px solid #e1e5ea}.cw-f input{flex:1;border:0;padding:12px;font:inherit;outline:none}" +
    ".cw-f button{border:0;background:#1B3A57;color:#fff;padding:0 16px;cursor:pointer}";
  document.head.appendChild(css);

  var btn = document.createElement("button");
  btn.className = "cw-btn"; btn.textContent = "💬"; btn.setAttribute("aria-label", "Open chat");
  var box = document.createElement("div");
  box.className = "cw-box";
  box.innerHTML = '<div class="cw-h"></div><div class="cw-m"></div><form class="cw-f"><input placeholder="Type a message..." maxlength="1000" required><button>Send</button></form>';
  box.querySelector(".cw-h").textContent = TITLE;
  document.body.appendChild(btn); document.body.appendChild(box);

  var msgs = box.querySelector(".cw-m"), form = box.querySelector("form"), input = box.querySelector("input");

  function add(text, who) {
    var d = document.createElement("div");
    d.className = "cw-b " + (who === "u" ? "cw-u" : "cw-a");
    d.textContent = text; // textContent, not innerHTML: no XSS from model output
    msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight;
    return d;
  }

  btn.onclick = function () {
    box.classList.toggle("open");
    if (!msgs.children.length) add("Hi! How can I help you today?", "a");
  };

  form.onsubmit = function (e) {
    e.preventDefault();
    var text = input.value.trim(); if (!text) return;
    input.value = ""; add(text, "u");
    var wait = add("...", "a");
    fetch(API, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ session_id: sid, message: text }) })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { wait.textContent = d.reply; })
      .catch(function () { wait.textContent = "Sorry, something went wrong. Please try again."; });
  };
})();
