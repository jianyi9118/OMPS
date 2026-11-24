/* 待办事项 (phase 5): everything waiting for me, read live; an item leaves the list once handled.
   已处理: what I handled (from the same business records), newest first. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var count = -1;
  var state = { tab: O.qs("tab") === "done" ? "done" : "pending", page: 1 };
  var built = "";

  var KIND_PILL = { issue_order: "warn", issue_read: "", note_read: "", perf_review: "", change_review: "", perf_returned: "bad", perf_write: "", staff_assign: "warn" };
  var RESULT_PILL = { done: "ok", read: "", approved: "ok", returned: "warn", assigned: "info", fixed: "ok", cancelled: "", confirmed: "ok", ignored: "" };

  function kindCol() {
    return { t: t("todo.col.kind"), f: function (r) { return '<span class="todo-k">' + O.pill(t("todo.kind." + r.kind), KIND_PILL[r.kind] || "") + "</span>"; } };
  }

  function when(r) {
    if (r.kind === "issue_order" && r.due) {
      return (r.overdue ? O.pill(t("issue.overdue"), "bad") + " " : "") + '<span class="mono">' + O.esc(t("issue.due_at", { due: r.due })) + "</span>" +
        '<div class="muted" style="font-size:12px">' + O.esc(O.left(r.due)) + "</div>";
    }
    if (r.kind === "perf_write" && r.due) return '<span class="mono">' + O.esc(t("todo.ends", { date: r.due })) + "</span>";
    return r.at ? '<span class="mono muted">' + O.esc(String(r.at).slice(0, 16)) + "</span>" : "";
  }

  function shell() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="td_tabs">' +
      '<button type="button" data-tab="pending">' + O.esc(t("todo.tab.pending")) + '</button><button type="button" data-tab="done">' + O.esc(t("todo.tab.done")) + "</button></div>" +
      '<div id="td_body" style="display:grid;gap:12px"></div>';
    document.getElementById("td_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b && b.getAttribute("data-tab") !== state.tab) { state.tab = b.getAttribute("data-tab"); state.page = 1; load(); }
    });
  }

  /* the bar is drawn once per tab (so the search box keeps its focus); each load redraws the card */
  function body(hint) {
    if (built !== state.tab || !document.getElementById("td_card")) {
      built = state.tab;
      var bs = document.querySelectorAll("#td_tabs button");
      for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", bs[i].getAttribute("data-tab") === state.tab);
      var el = document.getElementById("td_body");
      el.innerHTML = '<div class="bar"><span class="muted">' + O.esc(hint) + '</span><span class="sp"></span><button type="button" class="btn" id="td_refresh">' +
        O.icon("undo", 14) + " " + O.esc(t("todo.refresh")) + '</button></div><div class="card" id="td_card"></div>';
      var bar = el.querySelector(".bar");
      if (state.tab === "done") bar.insertBefore(O.filters({ state: state, onChange: load, period: t("todo.col.done_at"), placeholder: t("todo.search") }), bar.querySelector(".sp"));
      document.getElementById("td_refresh").addEventListener("click", load);
    }
    var card = document.getElementById("td_card");
    card.innerHTML = "";
    return card;
  }

  function renderPending(res) {
    count = res.counts.todo;
    var card = body(t("todo.hint"));
    card.appendChild(O.table({
      cols: [
        kindCol(),
        { t: t("todo.col.item"), cls: "wrap", f: function (r) { return (r.unread ? '<span class="udot" aria-hidden="true"></span>' : "") + O.esc(t("todo.msg." + r.kind, r.params)); } },
        { t: t("todo.col.when"), f: when }
      ],
      rows: res.rows, empty: t("todo.empty"),
      acts: function () { return [{ icon: "go", tip: t("todo.handle"), pri: true, fn: function (r) { O.go(r.link); } }]; }
    }));
    card.insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t("todo.count", { n: res.rows.length })) + "</div>");
  }

  function renderDone(res) {
    var card = body(t("todo.done_hint"));
    card.appendChild(O.table({
      cols: [
        kindCol(),
        { t: t("todo.col.item"), cls: "wrap", f: function (r) { return O.esc(t("todo.subj." + r.kind, r.params)); } },
        { t: t("todo.col.result"), f: function (r) { return O.pill(t("todo.result." + r.result), RESULT_PILL[r.result] || ""); } },
        { t: t("todo.col.done_at"), f: function (r) { return '<span class="mono muted">' + O.esc(String(r.at).slice(0, 16)) + "</span>"; } }
      ],
      rows: res.rows, empty: t(state.kw || state.from || state.to ? "todo.none_found" : "todo.done_empty"),
      acts: function () { return [{ icon: "go", tip: t("todo.view"), fn: function (r) { O.go(r.link); } }]; }
    }));
    card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
  }

  function load() {
    if (state.tab !== "done") {
      O.api("GET", "/api/v1/todo").then(renderPending, O.failed);
      return;
    }
    var q = O.filterQuery(state);
    q.page = state.page;
    O.api("GET", "/api/v1/todo/history", null, q).then(renderDone, O.failed);
  }

  O.onPulse("todo", function (p) { if (state.tab === "pending" && count >= 0 && p.counts && p.counts.todo !== count) load(); });
  shell();
  load();
})();
