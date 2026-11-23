/* 我的通知 (phase 5): every bell message; open = mark read and jump to the record */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { tab: "unread", page: 1 };
  var LEVEL = { info: "", warn: "warn", urgent: "bad" };

  function shell() {
    view.innerHTML = '<div class="bar" id="nt_bar"></div><div class="card" id="nt_card"></div>';
    document.getElementById("nt_bar").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-tab")) { state.tab = b.getAttribute("data-tab"); state.page = 1; load(); }
      if (b.id === "nt_all") O.api("POST", "/api/v1/notifications/read-all", {}).then(function () { O.status(t("notify.all_read")); O.pulse(); load(); }, O.failed);
    });
  }

  function render(res) {
    document.getElementById("nt_bar").innerHTML =
      '<button type="button" class="btn' + (state.tab === "unread" ? " pri" : "") + '" data-tab="unread">' + O.esc(t("notify.tab_unread", { n: res.unread })) + "</button>" +
      '<button type="button" class="btn' + (state.tab === "all" ? " pri" : "") + '" data-tab="all">' + O.esc(t("notify.tab_all")) + '</button><span class="sp"></span>' +
      (res.unread ? '<button type="button" class="btn" id="nt_all">' + O.icon("check", 14) + " " + O.esc(t("notify.read_all")) + "</button>" : "");
    var card = document.getElementById("nt_card");
    if (!res.rows.length) {
      card.innerHTML = '<div class="card-b muted">' + O.esc(t(state.tab === "unread" ? "notify.empty_unread" : "notify.none")) + "</div>";
      return;
    }
    card.innerHTML = '<ul class="list nlist">' + res.rows.map(function (n, i) {
      return '<li data-i="' + i + '" class="' + (n.read ? "" : "unread") + '"><span class="lvl">' + O.icon(n.level === "info" ? "bell" : "alert", 16) + '</span><span class="grow"><b>' +
        O.esc(O.notifyText(n)) + '</b><div class="sub">' + O.esc(O.ago(n.at)) + " · " + O.esc(n.at.slice(0, 16)) + (n.level !== "info" ? " " + O.pill(t("notify.level." + n.level), LEVEL[n.level]) : "") +
        "</div></span>" + (n.link ? '<span class="ib" aria-hidden="true">' + O.icon("go") + "</span>" : "") + "</li>";
    }).join("") + "</ul>";
    card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
    card.querySelector("ul").addEventListener("click", function (e) {
      var li = e.target.closest ? e.target.closest("li[data-i]") : null;
      if (!li) return;
      var n = res.rows[+li.getAttribute("data-i")];
      O.api("POST", "/api/v1/notifications/" + n.id + "/read", {}).then(function (r) { if (r.link) O.go(r.link); else { O.pulse(); load(); } }, O.failed);
    });
  }

  function load() {
    O.api("GET", "/api/v1/notifications", null, { tab: state.tab, page: state.page }).then(render, O.failed);
  }

  shell();
  load();
})();
