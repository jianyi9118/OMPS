/* Results review (phase 4): pending / reviewed, approve (one or many), edit and approve, return with a reason */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { tab: "pending", person_id: "", page: 1 };
  var data = null, picked = {};

  /* two sub-pages: review (pending / reviewed) and statistics (assets/js/pages/perfstats.js) */
  var page = O.qs("view") === "stats" ? "stats" : "review", built = { review: false, stats: false };
  function frame() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="rv_tabs" role="tablist">' +
      [["review", t("perf.st.tab_review")], ["stats", t("perf.st.tab")]].map(function (x) {
        return '<button type="button" role="tab" data-page="' + x[0] + '">' + O.esc(x[1]) + "</button>"; }).join("") +
      '</div><div id="rv_page_review" style="display:none;gap:12px"></div><div id="rv_page_stats" style="display:none;gap:12px"></div>';
    document.getElementById("rv_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-page]") : null;
      if (b) show(b.getAttribute("data-page"));
    });
  }
  function show(p) {
    page = p;
    var bs = document.querySelectorAll("#rv_tabs [data-page]");
    for (var i = 0; i < bs.length; i++) { var on = bs[i].getAttribute("data-page") === p; bs[i].classList.toggle("on", on); bs[i].setAttribute("aria-selected", on ? "true" : "false"); }
    // each sub-page shows alone (the inline display:grid would override the hidden attribute)
    document.getElementById("rv_page_review").style.display = p === "review" ? "grid" : "none";
    document.getElementById("rv_page_stats").style.display = p === "stats" ? "grid" : "none";
    if (window.history && history.replaceState) {
      var u = new URL(window.location.href);
      if (p === "stats") u.searchParams.set("view", "stats"); else u.searchParams.delete("view");
      u.searchParams.delete("focus");
      history.replaceState(null, "", u.pathname + u.search + u.hash);
    }
    if (built[p]) return;
    built[p] = true;
    if (p === "stats") O.perfStats.mount(document.getElementById("rv_page_stats"));
    else { shell(); load(); }
  }

  function shell() {
    // the bar is built once (the filters keep their input); drawBar() refreshes the tabs, people and batch actions
    document.getElementById("rv_page_review").innerHTML = '<div class="bar" id="rv_bar"><button type="button" class="btn" data-tab="pending"></button><button type="button" class="btn" data-tab="done"></button>' +
      '<select id="rv_person"></select><span class="sp"></span><span id="rv_acts" style="display:contents"></span></div><div class="card" id="rv_card"></div>';
    var bar = document.getElementById("rv_bar");
    bar.insertBefore(O.filters({ state: state, onChange: load, period: t("perf.rv.submitted_at"), placeholder: t("perf.rv.search"),
      fields: [["person", t("perf.rv.person")], ["dept", t("perf.rv.dept")], ["task", t("perf.rv.task")], ["done", t("perf.field.done")], ["problems", t("perf.label.problems")],
        ["next", t("perf.label.next")], ["reviewer", t("perf.rv.reviewer")], ["comment", t("perf.field.comment")]] }), bar.querySelector(".sp"));
    bar.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-tab")) { state.tab = b.getAttribute("data-tab"); state.page = 1; picked = {}; load(); }
      if (b.id === "rv_ok") approveMany(selected());
      if (b.id === "rv_back") returnMany(selected());
    });
    bar.addEventListener("change", function (e) {
      if (e.target.id === "rv_person") { state.person_id = e.target.value; state.page = 1; load(); }
      if (e.target.id === "rv_all") { (data ? data.rows : []).forEach(function (r) { if (e.target.checked) picked[r.id] = true; else delete picked[r.id]; }); render(); }
    });
  }

  function selected() { return Object.keys(picked).map(Number); }

  function drawBar() {
    var n = selected().length, pend = state.tab === "pending";
    var all = data && data.rows.length && data.rows.every(function (r) { return picked[r.id]; });
    var tp = document.querySelector('#rv_bar [data-tab="pending"]'), td = document.querySelector('#rv_bar [data-tab="done"]');
    tp.className = "btn" + (pend ? " pri" : "");
    tp.textContent = t("perf.rv.pending", { n: data ? data.pending : 0 });
    td.className = "btn" + (pend ? "" : " pri");
    td.textContent = t("perf.rv.done");
    document.getElementById("rv_person").innerHTML = '<option value="">' + O.esc(t("plan.all_people")) + "</option>" + (data ? data.people : []).map(function (x) {
      return '<option value="' + x.id + '"' + (String(x.id) === String(state.person_id) ? " selected" : "") + ">" + O.esc(x.name) + "</option>"; }).join("");
    document.getElementById("rv_acts").innerHTML =
      (pend && data && data.rows.length ? '<label class="opts" style="font-size:13px"><input type="checkbox" id="rv_all" style="width:auto"' + (all ? " checked" : "") + "> " + O.esc(t("perf.rv.select_all")) + "</label>" +
        '<span class="muted">' + O.esc(t("perf.rv.picked", { n: n })) + "</span>" +
        '<button type="button" class="btn" id="rv_back"' + (n ? "" : " disabled") + ">" + O.esc(t("perf.rv.return_many")) + "</button>" +
        '<button type="button" class="btn pri" id="rv_ok"' + (n ? "" : " disabled") + ">" + O.esc(t("perf.rv.approve_many", { n: n })) + "</button>" : "");
  }

  function render() {
    drawBar();
    var card = document.getElementById("rv_card");
    card.innerHTML = "";
    var pend = state.tab === "pending";
    var cols = [];
    if (pend) cols.push({ t: "", f: function (r) { return '<input type="checkbox" data-pick="' + r.id + '"' + (picked[r.id] ? " checked" : "") + ' aria-label="' + O.esc(t("perf.rv.pick")) + '">'; } });
    cols.push({ t: t("perf.rv.person"), f: function (r) { return O.esc(r.person) + (r.dept ? '<div class="muted" style="font-size:12px">' + O.esc(r.dept) + "</div>" : ""); } });
    cols.push({ t: t("perf.rv.task"), cls: "wrap", f: function (r) { return O.plan.levelPill(r.plan.level) + " " + O.esc(r.plan.title); } });
    cols.push({ t: t("plan.col.time"), f: function (r) { return '<span class="mono">' + O.esc(O.plan.label(r.plan)) + "</span>"; } });
    cols.push({ t: t("perf.field.progress"), cls: "num", f: function (r) { return O.esc(O.perf.pct(r.progress)); } });
    cols.push({ t: t("perf.rv.summary"), cls: "wrap", f: function (r) {
      return O.esc((r.done || "").slice(0, 80)) + (r.files ? ' <span class="muted" style="font-size:12px">' + O.esc(t("perf.rv.files", { n: r.files })) + "</span>" : "");
    } });
    if (pend) cols.push({ t: t("perf.rv.submitted_at"), f: function (r) { return '<span class="mono">' + O.esc(r.submitted_at.slice(0, 16)) + "</span>"; } });
    else cols.push({ t: t("perf.rv.result"), f: function (r) {
      return O.perf.statusPill(r.status) + '<div class="muted" style="font-size:12px">' + O.esc(r.reviewer + " · " + r.reviewed_at.slice(0, 16)) + (r.edited ? " · " + O.esc(t("perf.rv.edited")) : "") + "</div>";
    } });
    var tbl = O.table({
      cols: cols, rows: data.rows, empty: t(pend ? "perf.rv.empty" : "perf.rv.empty_done"),
      acts: function (r) {
        return [
          { icon: "eye", tip: t("perf.rv.view"), fn: function (x) { detail(x.id); } },
          pend ? { icon: "check", tip: t("perf.rv.approve"), pri: true, fn: function (x) { approveMany([x.id]); } } : null,
          pend ? { icon: "edit", tip: t("perf.rv.edit_approve"), fn: function (x) { editApprove(x.id); } } : null,
          pend || r.status === "approved" ? { icon: "undo", tip: t("perf.rv.return"), danger: true, fn: function (x) { returnMany([x.id]); } } : null
        ];
      }, slots: pend ? 4 : 2
    });
    card.appendChild(tbl);
    card.appendChild(O.pager(data, function (p) { state.page = p; load(); }));
    card.insertAdjacentHTML("beforeend", O.legend(pend ? [["eye", t("perf.rv.view")], ["check", t("perf.rv.approve")], ["edit", t("perf.rv.edit_approve")], ["undo", t("perf.rv.return")]]
      : [["eye", t("perf.rv.view")], ["undo", t("perf.rv.return")]]) + '<div class="card-b muted" style="font-size:12.5px;padding-top:0">' + O.esc(t("perf.rv.hint")) + "</div>");
    card.onchange = function (e) {
      var id = e.target.getAttribute && e.target.getAttribute("data-pick");
      if (id) { if (e.target.checked) picked[id] = true; else delete picked[id]; drawBar(); }
    };
  }

  function load() {
    var q = O.filterQuery(state);
    q.tab = state.tab;
    q.person_id = state.person_id;
    q.page = state.page;
    O.api("GET", "/api/v1/perf/reviews", null, q).then(function (res) {
      data = res;
      var ids = {};
      res.rows.forEach(function (r) { ids[r.id] = true; });
      Object.keys(picked).forEach(function (k) { if (!ids[k]) delete picked[k]; });
      render();
    }, O.failed);
  }

  function infoHtml(r) {
    return '<div class="fgrid">' +
      '<div class="fld"><label>' + O.esc(t("perf.rv.person")) + '</label><div class="ro">' + O.esc(r.person + (r.dept ? " · " + r.dept : "")) + "</div></div>" +
      '<div class="fld"><label>' + O.esc(t("perf.rv.task")) + '</label><div class="ro">' + O.plan.levelPill(r.plan.level) + " " + O.esc(r.plan.title) + ' <span class="muted mono">' + O.esc(O.plan.label(r.plan)) + "</span></div></div></div>";
  }

  function detail(id) {
    O.api("GET", "/api/v1/perf/reviews/" + id).then(function (r) {
      var el = document.createElement("div");
      el.innerHTML = infoHtml(r) + '<div class="fgrid" style="margin-top:10px">' +
        '<div class="fld"><label>' + O.esc(t("perf.field.progress")) + '</label><div class="ro">' + O.perf.progress(r.progress) + "</div></div>" +
        '<div class="fld"><label>' + O.esc(t("perf.rv.status")) + '</label><div class="ro">' + O.perf.statusPill(r.status) + (r.score !== null ? " " + O.esc(t("perf.score_is", { score: r.score })) : "") + "</div></div>" +
        '<div class="fld span2"><label>' + O.esc(t("perf.field.done")) + '</label><div class="ro">' + O.perf.para(r.done) + "</div></div>" +
        (r.problems ? '<div class="fld span2"><label>' + O.esc(t("perf.label.problems")) + '</label><div class="ro">' + O.perf.para(r.problems) + "</div></div>" : "") +
        (r.next ? '<div class="fld span2"><label>' + O.esc(t("perf.label.next")) + '</label><div class="ro">' + O.perf.para(r.next) + "</div></div>" : "") +
        (r.comment ? '<div class="fld span2"><label>' + O.esc(t("perf.field.comment")) + '</label><div class="ro">' + O.perf.para(r.comment) + "</div></div>" : "") +
        '<div class="fld span2"><label>' + O.esc(t("perf.files")) + "</label>" + (O.perf.filesHtml(r.files, false) || '<span class="muted">' + O.esc(t("file.none")) + "</span>") + "</div></div>";
      var btns = [{ t: t("perf.history"), fn: function () { O.perf.history(r.id); return false; } }];
      if (r.status === "submitted") {
        btns.push({ t: t("perf.rv.return"), cls: "danger", fn: function () { returnMany([r.id]); } });
        btns.push({ t: t("perf.rv.edit_approve"), fn: function () { editApprove(r.id); } });
        btns.push({ t: t("perf.rv.approve"), cls: "pri", fn: function () { return O.api("POST", "/api/v1/perf/reviews/approve", { ids: [r.id] }).then(function () { O.status(t("perf.rv.approved", { n: 1 })); load(); }); } });
      } else if (r.status === "approved") {
        btns.push({ t: t("perf.rv.return"), cls: "danger", fn: function () { returnMany([r.id]); } });
      }
      btns.push({ t: t("common.close") });
      O.modal({ title: t("perf.rv.view_title"), width: 760, body: el, buttons: btns });
    }, O.failed);
  }

  function approveMany(ids) {
    if (!ids.length) return;
    O.confirm({ title: t("perf.rv.approve"), msg: t("perf.rv.approve_confirm", { n: ids.length }), ok: t("perf.rv.approve") }).then(function (ok) {
      if (!ok) return;
      O.api("POST", "/api/v1/perf/reviews/approve", { ids: ids }).then(function (r) { O.status(t("perf.rv.approved", { n: r.approved })); picked = {}; load(); }, function (e) { O.failed(e); load(); });
    });
  }

  function returnMany(ids) {
    if (!ids.length) return;
    var f = O.form([{ name: "reason", label: t("perf.field.reason"), type: "textarea", req: true, max: 500, rows: 4, hint: ids.length > 1 ? t("perf.rv.reason_shared", { n: ids.length }) : "" }], {});
    O.modal({ title: t("perf.rv.return_title", { n: ids.length }), width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("perf.rv.return"), cls: "danger fill", fn: function () {
      return O.submit(f, O.api("POST", "/api/v1/perf/reviews/return", { ids: ids, reason: f.values().reason })).then(function (r) { O.status(t("perf.rv.returned", { n: r.returned })); picked = {}; load(); });
    } }] });
  }

  function editApprove(id) {
    O.api("GET", "/api/v1/perf/reviews/" + id).then(function (r) {
      var box = document.createElement("div");
      box.innerHTML = infoHtml(r) + '<p class="muted" style="font-size:12.5px">' + O.esc(t("perf.rv.edit_hint")) + "</p>";
      var f = O.form([
        { name: "progress", label: t("perf.field.progress"), max: 6, req: true },
        { name: "score", label: t("perf.field.score"), max: 6, hint: t("perf.score_hint") },
        { name: "done", label: t("perf.field.done"), type: "textarea", rows: 6, max: 5000, req: true },
        { name: "problems", label: t("perf.field.problems"), type: "textarea", rows: 3, max: 2000 },
        { name: "next", label: t("perf.field.next"), type: "textarea", rows: 3, max: 2000 },
        { name: "comment", label: t("perf.field.comment"), type: "textarea", rows: 2, max: 500 }
      ], { progress: r.progress === null ? "" : String(r.progress), done: r.done, problems: r.problems, next: r.next });
      box.appendChild(f.el);
      O.modal({ title: t("perf.rv.edit_approve"), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("perf.rv.save_approve"), cls: "pri", fn: function () {
        var v = f.values();
        v.version = r.version;
        return O.submit(f, O.api("POST", "/api/v1/perf/reviews/" + id + "/approve", v)).then(function () { O.status(t("perf.rv.approved", { n: 1 })); load(); });
      } }] });
    }, O.failed);
  }

  frame();
  O.plan.meta().then(function () {
    var focus = +O.qs("focus");
    show(focus ? "review" : page);
    if (focus) {
      O.api("GET", "/api/v1/perf/reviews/" + focus).then(function (r) {
        if (r.status !== "submitted") state.tab = "done";
        O.focusBar(t("perf.rv.located", { name: r.person, title: r.plan.title }));
        if (state.tab === "done") load();
        detail(focus);
      }, O.failed);
    }
  }, O.failed);
})();
