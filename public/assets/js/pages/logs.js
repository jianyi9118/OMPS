/* Logs: operation / audit / login (read only) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { type: "operation", page: 1, from: "", to: "", user: "", module: "", result: "" };

  view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="lg_tabs">' +
    ["operation", "audit", "login"].map(function (k) { return '<button type="button" data-tab="' + k + '">' + O.esc(t("log.tab." + k)) + "</button>"; }).join("") + "</div>" +
    '<div class="bar"><label class="scope">' + O.esc(t("log.from")) + ' ' + O.dateInput('lg_from') + '</label><label class="scope">' + O.esc(t("log.to")) + ' ' + O.dateInput('lg_to') + '</label>' +
    '<input type="search" id="lg_user" placeholder="' + O.esc(t("log.user")) + '"><select id="lg_module"></select><select id="lg_result" hidden></select><span class="sp"></span></div><div class="card" id="lg_card"></div>';

  var resultSel = document.getElementById("lg_result");
  resultSel.innerHTML = '<option value="">' + O.esc(t("log.all_results")) + "</option>" + ["success", "fail", "locked", "disabled", "ip_blocked", "logout"].map(function (k) {
    return '<option value="' + k + '">' + O.esc(t("log.login." + k)) + "</option>";
  }).join("");

  function json(v) {
    if (v === null || v === undefined) return "";
    return '<span class="mono" style="font-size:12px;white-space:normal;word-break:break-all">' + O.esc(JSON.stringify(v)) + "</span>";
  }

  function cols() {
    if (state.type === "login") {
      return [
        { t: t("log.time"), f: function (r) { return '<span class="mono">' + O.esc(r.time) + "</span>"; } },
        { t: t("log.user"), f: function (r) { return O.esc(r.user); } },
        { t: t("log.result"), f: function (r) { return O.pill(r.result_name, r.result === "success" || r.result === "logout" ? "ok" : "bad"); } },
        { t: t("log.reason"), f: function (r) { return O.esc(r.reason); } },
        { t: "IP", f: function (r) { return '<span class="mono">' + O.esc(r.ip) + "</span>"; } }
      ];
    }
    var c = [
      { t: t("log.time"), f: function (r) { return '<span class="mono">' + O.esc(r.time) + "</span>"; } },
      { t: t("log.user"), f: function (r) { return O.esc(r.user || "—"); } },
      { t: t("log.module"), f: function (r) { return O.esc(r.module); } },
      { t: t("log.action"), f: function (r) { return O.esc(r.action); } },
      { t: t("log.target"), cls: "wrap", f: function (r) { return O.esc(r.target); } }
    ];
    if (state.type === "audit") {
      c.push({ t: t("log.before"), cls: "wrap", f: function (r) { return json(r.before); } });
      c.push({ t: t("log.after"), cls: "wrap", f: function (r) { return json(r.after); } });
    }
    c.push({ t: "IP", f: function (r) { return '<span class="mono">' + O.esc(r.ip) + "</span>"; } });
    return c;
  }

  function load() {
    var tabs = document.querySelectorAll("#lg_tabs button");
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle("on", tabs[i].getAttribute("data-tab") === state.type);
    document.getElementById("lg_module").hidden = state.type === "login";
    resultSel.hidden = state.type !== "login";
    O.api("GET", "/api/v1/logs/" + state.type, null, { page: state.page, from: state.from, to: state.to, user: state.user, module: state.module, result: state.result }).then(function (res) {
      var sel = document.getElementById("lg_module");
      if (state.type !== "login") {
        sel.innerHTML = '<option value="">' + O.esc(t("log.all_modules")) + "</option>" + res.modules.map(function (m) {
          return '<option value="' + O.esc(m.code) + '"' + (m.code === state.module ? " selected" : "") + ">" + O.esc(m.name) + "</option>";
        }).join("");
      }
      var card = document.getElementById("lg_card");
      card.innerHTML = "";
      card.appendChild(O.table({ cols: cols(), rows: res.rows }));
      card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
    }, O.failed);
  }

  document.getElementById("lg_tabs").addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-tab]") : null;
    if (!b) return;
    state.type = b.getAttribute("data-tab");
    state.page = 1;
    state.module = "";
    state.result = "";
    resultSel.value = "";
    load();
  });
  document.getElementById("lg_from").addEventListener("change", function (e) { state.from = e.target.value; state.page = 1; load(); });
  document.getElementById("lg_to").addEventListener("change", function (e) { state.to = e.target.value; state.page = 1; load(); });
  document.getElementById("lg_user").addEventListener("input", O.debounce(function (e) { state.user = e.target.value.trim(); state.page = 1; load(); }, 350));
  document.getElementById("lg_module").addEventListener("change", function (e) { state.module = e.target.value; state.page = 1; load(); });
  resultSel.addEventListener("change", function (e) { state.result = e.target.value; state.page = 1; load(); });
  load();
})();
