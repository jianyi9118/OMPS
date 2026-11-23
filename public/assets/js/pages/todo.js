/* 待办事项 (phase 5): everything waiting for me, read live; an item leaves the list once handled */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var count = -1;

  var KIND_PILL = { issue_order: "warn", issue_read: "", note_read: "", perf_review: "", change_review: "", perf_returned: "bad", perf_write: "", staff_assign: "warn" };

  function when(r) {
    if (r.kind === "issue_order" && r.due) {
      return (r.overdue ? O.pill(t("issue.overdue"), "bad") + " " : "") + '<span class="mono">' + O.esc(t("issue.due_at", { due: r.due })) + "</span>" +
        '<div class="muted" style="font-size:12px">' + O.esc(O.left(r.due)) + "</div>";
    }
    if (r.kind === "perf_write" && r.due) return '<span class="mono">' + O.esc(t("todo.ends", { date: r.due })) + "</span>";
    return r.at ? '<span class="mono muted">' + O.esc(String(r.at).slice(0, 16)) + "</span>" : "";
  }

  function render(res) {
    count = res.counts.todo;
    view.innerHTML = '<div class="bar"><span class="muted">' + O.esc(t("todo.hint")) + '</span><span class="sp"></span><button type="button" class="btn" id="td_refresh">' +
      O.icon("undo", 14) + " " + O.esc(t("todo.refresh")) + '</button></div><div class="card" id="td_card"></div>';
    document.getElementById("td_refresh").addEventListener("click", load);
    var card = document.getElementById("td_card");
    card.appendChild(O.table({
      cols: [
        { t: t("todo.col.kind"), f: function (r) { return '<span class="todo-k">' + O.pill(t("todo.kind." + r.kind), KIND_PILL[r.kind] || "") + "</span>"; } },
        { t: t("todo.col.item"), cls: "wrap", f: function (r) { return (r.unread ? '<span class="udot" aria-hidden="true"></span>' : "") + O.esc(t("todo.msg." + r.kind, r.params)); } },
        { t: t("todo.col.when"), f: when }
      ],
      rows: res.rows, empty: t("todo.empty"),
      acts: function () { return [{ icon: "go", tip: t("todo.handle"), pri: true, fn: function (r) { O.go(r.link); } }]; }
    }));
    card.insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t("todo.count", { n: res.rows.length })) + "</div>");
  }

  function load() {
    O.api("GET", "/api/v1/todo").then(render, O.failed);
  }

  O.onPulse("todo", function (p) { if (count >= 0 && p.counts && p.counts.todo !== count) load(); });
  load();
})();
