/* 记事本 (phase 5): private notes, one-button submit to the superior, received notes with a comment */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var meta = null;
  var state = { box: O.qs("tab") === "inbox" ? "inbox" : "mine", filter: "", page: 1, id: +O.qs("id") || 0 };
  var list = [], lastRes = null, headKey = "", cur = null, dirty = false, focusId = state.id;

  var PILL = { private: "", submitted: "warn", read: "ok" };
  function statusPill(st, inbox) { return O.pill(t((inbox ? "note.in_status." : "note.status.") + st), PILL[st]); }
  function para(s) { return O.esc(s || "").replace(/\n/g, "<br>"); }

  function shell() {
    view.innerHTML = '<div class="nb"><div class="card nbl" id="nb_l"><div id="nb_head"></div><div id="nb_ls"></div></div><div class="card" id="nb_r"></div></div>';
    var l = document.getElementById("nb_l");
    l.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-box")) {
        leave().then(function () {
          state.box = b.getAttribute("data-box"); state.filter = ""; state.id = 0; cur = null;
          // the other box has other fields and dates: start it unfiltered
          state.period = "all"; state.from = ""; state.to = ""; state.kw = ""; state.field = ""; state.page = 1;
          loadList();
        });
      }
      else if (b.hasAttribute("data-f")) { state.filter = b.getAttribute("data-f"); state.page = 1; loadList(); }
      else if (b.hasAttribute("data-id")) { var id = +b.getAttribute("data-id"); if (!cur || cur.id !== id) leave().then(function () { open(id); }); }
      else if (b.id === "nb_new") { leave().then(newNote); }
    });
  }

  /* the head (boxes, chips, filters) is drawn again only when the box or the unread count changes, so the search box keeps its focus */
  function drawHead() {
    var inbox = state.box === "inbox";
    var key = state.box + "|" + (meta.inbox ? meta.inbox_unread : "");
    if (key === headKey && document.querySelector("#nb_head .bar")) return;
    headKey = key;
    var chips = inbox ? [["", t("note.f.all")], ["unread", t("note.f.unread")]] : [["", t("note.f.all")], ["private", t("note.f.private")], ["submitted", t("note.f.submitted")]];
    var h = document.getElementById("nb_head");
    h.innerHTML = (meta.inbox ? '<div class="subnav" style="border-bottom:1px solid var(--line-2)">' +
        '<button type="button" data-box="mine" class="' + (inbox ? "" : "on") + '">' + O.esc(t("note.box.mine")) + "</button>" +
        '<button type="button" data-box="inbox" class="' + (inbox ? "on" : "") + '">' + O.esc(t("note.box.inbox")) + (meta.inbox_unread ? " (" + meta.inbox_unread + ")" : "") + "</button></div>" : "") +
      '<div class="bar" style="display:grid;gap:8px">' +
      (inbox ? "" : '<div style="display:flex;gap:8px;align-items:center"><b style="flex:1">' + (meta.inbox ? "" : O.esc(t("note.box.mine"))) + "</b>" +
        '<button type="button" class="btn sm pri" id="nb_new">' + O.icon("plus", 14) + " " + O.esc(t("note.new")) + "</button></div>") +
      '<div class="chips">' + chips.map(function (c) { return '<button type="button" class="chip" data-f="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") + "</div></div>";
    var fields = [["title", t("note.field.title")], ["body", t("note.field.body")], ["reply", t("note.field.reply")]];
    if (inbox) fields.splice(2, 0, ["owner", t("note.col.owner")]);
    h.querySelector(".bar").appendChild(O.filters({ state: state, onChange: loadList, compact: true, period: t(inbox ? "note.col.submitted_at" : "note.col.updated_at"), fields: fields,
      placeholder: t("note.search") }));
  }

  function drawLeft() {
    drawHead();
    var inbox = state.box === "inbox";
    var cs = document.querySelectorAll("#nb_head [data-f]");
    for (var i = 0; i < cs.length; i++) cs[i].classList.toggle("on", cs[i].getAttribute("data-f") === state.filter);
    var l = document.getElementById("nb_ls");
    var filtered = state.kw || state.from || state.to;
    l.innerHTML = '<div class="nbls" id="nb_list">' + (list.length ? list.map(function (n) {
        var sub = inbox ? t("note.from", { name: n.owner }) + " · " + n.submitted_at.slice(5, 16) : (n.snippet || t("note.no_body")) ;
        return '<button type="button" class="nbi' + (cur && cur.id === n.id ? " on" : "") + '" data-id="' + n.id + '"><div class="nbi-h"><b>' + (inbox && n.status === "submitted" ? '<span class="udot"></span>' : "") +
          O.esc(n.title) + "</b>" + statusPill(n.status, inbox) + '</div><div class="nbi-s">' + O.esc(sub) + '</div><div class="nbi-m">' + O.esc(inbox ? (n.read_at ? t("note.read_at", { at: n.read_at.slice(5, 16) }) : "") : n.updated_at.slice(5, 16)) + "</div></button>";
      }).join("") : '<div class="card-b muted">' + O.esc(t(filtered ? "note.none_found" : inbox ? "note.inbox_empty" : "note.empty")) + "</div>") + "</div>";
    if (lastRes) l.appendChild(O.pager(lastRes, function (p) { state.page = p; loadList(); }));
  }

  function loadList() {
    var q = O.filterQuery(state);
    q.box = state.box;
    q.filter = state.filter;
    q.page = state.page;
    return O.api("GET", "/api/v1/notes", null, q).then(function (res) {
      list = res.rows;
      lastRes = res;
      drawLeft();
      if (!cur) {
        if (state.id) { open(state.id); state.id = 0; }
        else if (list.length && state.box === "mine") open(list[0].id);
        else if (state.box === "mine" && !(state.kw || state.from || state.to)) newNote();
        else document.getElementById("nb_r").innerHTML = '<div class="card-b muted">' + O.esc(t(list.length ? "note.pick" : state.kw || state.from || state.to ? "note.none_found" : "note.inbox_empty")) + "</div>";
      }
    }, O.failed);
  }

  function refreshMeta() {
    return O.api("GET", "/api/v1/notes/meta").then(function (m) { meta = m; });
  }

  /* ---------- editor (own private note) ---------- */
  function planOpts(sel) {
    return '<option value="">' + O.esc(t("note.no_plan")) + "</option>" + meta.plans.map(function (p) {
      return '<option value="' + p.id + '"' + (sel === p.id ? " selected" : "") + ">" + O.esc(t("plan.level." + p.level) + " · " + p.title) + "</option>";
    }).join("");
  }

  function editor(n) {
    cur = n;
    dirty = false;
    var r = document.getElementById("nb_r");
    var sup = meta.superior;
    r.innerHTML = '<div class="card-b nbed">' +
      '<input class="inp nbt" id="ne_title" maxlength="200" placeholder="' + O.esc(t("note.field.title")) + '" value="' + O.esc(n.title || "") + '">' +
      '<textarea class="inp nbb" id="ne_body" maxlength="50000" placeholder="' + O.esc(t("note.body_ph")) + '">' + O.esc(n.body || "") + "</textarea>" +
      '<label class="muted" style="font-size:12.5px">' + O.esc(t("note.field.plan")) + ' <select class="inp" id="ne_plan" style="width:auto;max-width:100%">' + planOpts(n.plan ? n.plan.id : null) + "</select></label>" +
      '<div class="nbfoot"><span class="muted" id="ne_state" style="font-size:12.5px"></span><span style="flex:1"></span>' +
      (n.id ? '<button type="button" class="btn danger" id="ne_del">' + O.esc(t("common.delete")) + "</button>" : "") +
      (sup ? '<button type="button" class="btn" id="ne_submit">' + O.esc(t("note.submit_to", { name: sup.name })) + "</button>" : "") +
      '<button type="button" class="btn pri" id="ne_save">' + O.esc(t("common.save")) + "</button></div>" +
      (sup ? "" : '<div class="muted" style="font-size:12.5px">' + O.esc(t("note.no_superior")) + "</div>") + "</div>";
    stateLine();
    ["ne_title", "ne_body", "ne_plan"].forEach(function (k) { document.getElementById(k).addEventListener("input", function () { dirty = true; stateLine(); }); });
    document.getElementById("ne_plan").addEventListener("change", function () { dirty = true; stateLine(); });
    document.getElementById("ne_save").addEventListener("click", function () { save(true); });
    var del = document.getElementById("ne_del");
    if (del) del.addEventListener("click", remove);
    var sb = document.getElementById("ne_submit");
    if (sb) sb.addEventListener("click", submitNote);
    if (!n.id) document.getElementById("ne_title").focus();
  }

  function stateLine() {
    var el = document.getElementById("ne_state");
    if (!el || !cur) return;
    el.textContent = t("note.private_only") + " · " + (dirty ? t("note.unsaved") : cur.updated_at ? t("note.saved_at", { at: cur.updated_at.slice(5, 16) }) : t("note.unsaved"));
  }

  function values() {
    var title = document.getElementById("ne_title").value.trim();
    var body = document.getElementById("ne_body").value;
    if (!title) title = body.trim() ? body.trim().split("\n")[0].slice(0, 30) : "";
    var plan = document.getElementById("ne_plan").value;
    return { title: title, body: body, plan_id: plan ? +plan : null, version: cur && cur.version ? cur.version : null };
  }

  /* save; returns a promise. Empty new notes are dropped silently. */
  function save(loud) {
    if (!cur || !document.getElementById("ne_title")) return Promise.resolve();
    var v = values();
    if (!cur.id && !v.title && !v.body.trim()) return Promise.resolve();
    if (!v.title) v.title = t("note.untitled");
    var p = cur.id ? O.api("PUT", "/api/v1/notes/" + cur.id, v) : O.api("POST", "/api/v1/notes", v);
    return p.then(function (r) {
      cur.id = r.id;
      cur.version = r.version;
      cur.title = v.title;
      cur.updated_at = new Date().toISOString().replace("T", " ").slice(0, 19);
      var ti = document.getElementById("ne_title");
      if (ti && !ti.value.trim()) ti.value = v.title;
      dirty = false;
      stateLine();
      if (loud) O.status(t("common.saved"));
      return loadList();
    }, function (e) { O.failed(e); throw e; });
  }

  /* switching away saves the open note first, so nothing is lost */
  function leave() {
    if (cur && dirty && document.getElementById("ne_title")) return save(false).then(function () { cur = null; }, function () { return Promise.reject(); });
    cur = null;
    return Promise.resolve();
  }

  function newNote() {
    editor({ id: 0, title: "", body: "", plan: null, updated_at: "" });
    drawLeft();
  }

  function remove() {
    O.confirm({ title: t("note.delete_title"), msg: t("note.delete_confirm", { title: cur.title }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (!ok) return;
      O.api("DELETE", "/api/v1/notes/" + cur.id).then(function () { cur = null; dirty = false; O.status(t("note.deleted")); loadList(); }, O.failed);
    });
  }

  function submitNote() {
    var go = function () {
      O.confirm({ title: t("note.submit_title"), msg: t("note.submit_confirm", { name: meta.superior.name }), ok: t("note.submit_to", { name: meta.superior.name }) }).then(function (ok) {
        if (!ok) return;
        O.api("POST", "/api/v1/notes/" + cur.id + "/submit", {}).then(function (r) { O.status(t("note.submitted", { name: r.to })); var id = cur.id; cur = null; loadList().then(function () { open(id); }); }, O.failed);
      });
    };
    if (dirty || !cur.id) save(false).then(go, function () {}); else go();
  }

  /* ---------- read-only view (own submitted / read, or received) ---------- */
  function viewer(n) {
    cur = n;
    dirty = false;
    var r = document.getElementById("nb_r");
    var trail = [];
    if (n.mine) {
      if (n.submitted_at) trail.push(t("note.trail_submitted", { name: n.to, at: n.submitted_at.slice(0, 16) }));
    } else {
      trail.push(t("note.trail_from", { name: n.owner, at: n.submitted_at.slice(0, 16) }));
    }
    if (n.read_at) trail.push(t("note.trail_read", { name: n.mine ? n.to : t("note.me"), at: n.read_at.slice(0, 16) }));
    r.innerHTML = '<div class="card-b nbview"><div class="nbv-h"><h3>' + O.esc(n.title) + "</h3>" + statusPill(n.status, !n.mine) + "</div>" +
      '<div class="nbtrail">' + trail.map(function (x) { return "<span>" + O.icon("dot", 12) + " " + O.esc(x) + "</span>"; }).join("") + "</div>" +
      (n.plan ? '<div class="muted" style="font-size:12.5px">' + O.esc(t("note.plan_is", { plan: t("plan.level." + n.plan.level) + " · " + n.plan.title })) + "</div>" : "") +
      '<div style="line-height:1.7;white-space:normal">' + (n.body ? para(n.body) : '<span class="muted">' + O.esc(t("note.no_body")) + "</span>") + "</div>" +
      (n.status === "read" ? '<div class="nbreply"><b>' + O.esc(t("note.reply_label")) + "</b> " + (n.reply ? para(n.reply) : '<span class="muted">' + O.esc(t("note.no_reply")) + "</span>") + "</div>" : "") +
      (n.caps.read ? '<div class="fld"><label>' + O.esc(t("note.field.reply")) + '</label><textarea class="inp" id="nv_reply" rows="3" maxlength="2000" placeholder="' + O.esc(t("note.reply_ph")) + '"></textarea></div>' : "") +
      '<div class="nbfoot"><span style="flex:1" class="muted">' + O.esc(n.mine && n.status === "submitted" ? t("note.locked_hint") : n.mine && n.status === "read" ? t("note.read_hint") : "") + "</span>" +
      (n.caps.withdraw ? '<button type="button" class="btn" id="nv_withdraw">' + O.esc(t("note.withdraw")) + "</button>" : "") +
      (n.caps.copy ? '<button type="button" class="btn" id="nv_copy">' + O.esc(t("note.copy")) + "</button>" : "") +
      (n.caps.read ? '<button type="button" class="btn pri" id="nv_read">' + O.esc(t("note.mark_read")) + "</button>" : "") + "</div></div>";
    var b;
    if ((b = document.getElementById("nv_withdraw"))) b.addEventListener("click", function () {
      O.api("POST", "/api/v1/notes/" + n.id + "/withdraw", {}).then(function () { O.status(t("note.withdrawn")); cur = null; loadList().then(function () { open(n.id); }); }, O.failed);
    });
    if ((b = document.getElementById("nv_copy"))) b.addEventListener("click", function () {
      O.api("POST", "/api/v1/notes/" + n.id + "/copy", {}).then(function (x) { O.status(t("note.copied")); state.filter = ""; state.page = 1; cur = null; loadList().then(function () { open(x.id); }); }, O.failed);
    });
    if ((b = document.getElementById("nv_read"))) b.addEventListener("click", function () {
      O.api("POST", "/api/v1/notes/" + n.id + "/read", { reply: document.getElementById("nv_reply").value }).then(function () {
        O.status(t("note.marked_read")); O.pulse(); cur = null; refreshMeta().then(loadList).then(function () { open(n.id); });
      }, O.failed);
    });
  }

  function open(id) {
    return O.api("GET", "/api/v1/notes/" + id).then(function (n) {
      var want = n.mine ? "mine" : "inbox", switched = state.box !== want;
      state.box = want;
      if (switched) { state.filter = ""; state.period = "all"; state.from = ""; state.to = ""; state.kw = ""; state.field = ""; state.page = 1; }
      if (n.caps.edit) editor(n); else viewer(n);
      return (switched ? loadList() : Promise.resolve(drawLeft())).then(function () {
        if (focusId !== n.id) return;
        focusId = 0;
        O.focusBar(t("note.located", { title: n.title }));
        O.flash(document.querySelector('.nbi[data-id="' + n.id + '"]'));
        O.pulse();
      });
    }, function (e) { O.failed(e); cur = null; });
  }

  window.addEventListener("beforeunload", function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ""; }
  });

  shell();
  refreshMeta().then(loadList, O.failed);
})();
