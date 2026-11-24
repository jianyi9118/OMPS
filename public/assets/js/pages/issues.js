/* 下发事项 (phase 5): received items (read / complete and reply), items I sent (progress, nudge, withdraw), send form */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var meta = null;
  var state = { tab: O.qs("tab") === "sent" ? "sent" : "in", filter: "pending", page: 1 };
  var sstate = { filter: "all", page: 1 };
  var list = [], lastRes = null, curId = 0, focusId = +O.qs("id") || 0;
  var replyFiles = [];

  var KIND_PILL = { tip: "", doc: "", order: "warn" };
  function para(s) { return O.esc(s || "").replace(/\n/g, "<br>"); }
  function filesHtml(files) {
    if (!files || !files.length) return "";
    return '<div class="isfiles">' + files.map(function (f) {
      return '<div class="isf"><span class="grow"><a class="lk" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '">' + O.esc(f.name) + '</a> <span class="muted" style="font-size:12px">' +
        O.esc(O.fileSize(f.size)) + "</span></span>" + (f.image ? '<a class="ib" target="_blank" rel="noopener" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id, { inline: 1 })) + '" title="' +
        O.esc(t("file.preview")) + '">' + O.icon("eye") + "</a>" : "") + '<a class="ib" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '" title="' + O.esc(t("issue.download")) + '">' + O.icon("download") + "</a></div>";
    }).join("") + "</div>";
  }
  function statePill(x) {
    if (x.status === "withdrawn") return O.pill(t("issue.st.withdrawn"));
    if (x.overdue) return O.pill(t("issue.st.overdue"), "bad");
    if (x.need_reply) return x.done ? O.pill(t("issue.st.done"), "ok") : O.pill(t("issue.st.todo"), "warn");
    return x.read ? O.pill(t("issue.st.read"), "ok") : O.pill(t("issue.st.unread"), "warn");
  }

  function shell() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="is_tabs"></div><div id="is_body" style="display:grid;gap:12px"></div>';
    document.getElementById("is_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (!b) return;
      state.tab = b.getAttribute("data-tab");
      curId = 0;
      render();
    });
  }

  function drawTabs() {
    var tabs = [["in", t("issue.tab.in") + (meta.pending ? " (" + meta.pending + ")" : "")]];
    if (meta.can_send) tabs.push(["sent", t("issue.tab.sent")]);
    var el = document.getElementById("is_tabs");
    el.innerHTML = tabs.map(function (x) { return '<button type="button" data-tab="' + x[0] + '" class="' + (state.tab === x[0] ? "on" : "") + '">' + O.esc(x[1]) + "</button>"; }).join("");
    el.hidden = tabs.length < 2;
  }

  function render() {
    drawTabs();
    if (state.tab === "sent" && meta.can_send) sentView(); else inView();
  }

  function refreshMeta() {
    return O.api("GET", "/api/v1/issues/meta").then(function (m) { meta = m; });
  }

  /* ---------- received ---------- */
  function inView() {
    var body = document.getElementById("is_body");
    body.innerHTML = '<div class="nb"><div class="card nbl" id="ii_l"><div class="bar"><b style="flex:1">' + O.esc(t("issue.tab.in")) + '</b><div class="chips">' +
      [["pending", t("issue.f.pending")], ["all", t("issue.f.all")]].map(function (c) { return '<button type="button" class="chip" data-f="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") +
      '</div></div><div id="ii_ls"></div></div><div class="card" id="ii_r"><div class="card-b muted">' + O.esc(t("issue.pick")) + "</div></div></div>";
    var l = document.getElementById("ii_l");
    l.querySelector(".bar").appendChild(O.filters({ state: state, onChange: loadIn, compact: true, period: t("issue.col.at"),
      fields: [["title", t("issue.field.title")], ["body", t("issue.field.body")], ["sender", t("issue.col.sender")]], placeholder: t("issue.search") }));
    l.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-f")) { state.filter = b.getAttribute("data-f"); state.page = 1; loadIn(); }
      if (b.hasAttribute("data-id")) openIn(+b.getAttribute("data-id"));
    });
    loadIn();
  }

  function drawInList(res) {
    var l = document.getElementById("ii_ls");
    if (!l) return;
    var cs = document.querySelectorAll("#ii_l [data-f]");
    for (var i = 0; i < cs.length; i++) cs[i].classList.toggle("on", cs[i].getAttribute("data-f") === state.filter);
    var filtered = state.kw || state.from || state.to;
    l.innerHTML = '<div class="nbls">' + (list.length ? list.map(function (x) {
        var sub = t("issue.kind." + x.kind) + " · " + x.sender + " · " + (x.need_reply && x.due ? (x.done ? t("issue.due_at", { due: x.due }) : O.left(x.due)) : x.at.slice(5, 16));
        return '<button type="button" class="nbi' + (x.status === "withdrawn" ? " off" : "") + (curId === x.id ? " on" : "") + '" data-id="' + x.id + '"><div class="nbi-h"><b>' +
          (!x.read && x.status === "active" ? '<span class="udot"></span>' : "") + O.esc(x.title) + "</b>" + statePill(x) + '</div><div class="nbi-s"><span class="' + (x.overdue ? "bad" : "") + '">' + O.esc(sub) + "</span></div></button>";
      }).join("") : '<div class="card-b muted">' + O.esc(t(filtered ? "issue.none_found" : state.filter === "pending" ? "issue.in_empty" : "issue.in_none")) + "</div>") + "</div>";
    l.appendChild(O.pager(res, function (p) { state.page = p; loadIn(); }));
  }

  function inQuery(filter, page) {
    var q = O.filterQuery(state);
    q.box = "in";
    q.filter = filter;
    q.page = page;
    return q;
  }

  function loadIn() {
    return O.api("GET", "/api/v1/issues", null, inQuery(state.filter, state.page)).then(function (res) {
      list = res.rows;
      lastRes = res;
      drawInList(res);
      // opening an item marks it read, so nothing is opened without a click (or a jump link)
      if (focusId) { var f = focusId; openIn(f); }
    }, O.failed);
  }

  function openIn(id) {
    curId = id;
    replyFiles = [];
    O.api("GET", "/api/v1/issues/" + id).then(function (x) {
      if (x.role === "sender") { focusId = 0; state.tab = "sent"; render(); detail(x.id); return; }
      var m = x.mine;
      var r = document.getElementById("ii_r");
      var dueBox = x.need_reply && x.due ? '<div class="isdue' + (m.overdue ? " bad" : "") + '">' + O.esc(t("issue.due_at", { due: x.due })) + " · " +
        O.esc(m.done_at ? (m.late ? t("issue.done_late") : t("issue.done_in_time")) : O.left(x.due)) + "</div>" : "";
      var canReply = x.status === "active" && x.need_reply && !m.done_at;
      r.innerHTML = '<div class="card-b nbview"><div class="nbv-h"><h3>' + O.esc(x.title) + "</h3>" + O.pill(t("issue.kind." + x.kind), KIND_PILL[x.kind]) + " " +
        statePill({ status: x.status, overdue: m.overdue, need_reply: x.need_reply, done: !!m.done_at, read: true }) + "</div>" +
        '<div class="muted">' + O.esc(t("issue.from", { name: x.sender + (x.sender_dept ? " · " + x.sender_dept : ""), at: x.at.slice(0, 16) })) + "</div>" +
        (x.status === "withdrawn" ? '<div class="note">' + O.esc(t("issue.withdrawn_note", { at: x.withdrawn_at.slice(0, 16) })) + "</div>" : "") + dueBox +
        '<div style="line-height:1.7">' + (x.body ? para(x.body) : '<span class="muted">' + O.esc(t("issue.no_body")) + "</span>") + "</div>" + filesHtml(x.files) +
        (m.done_at ? '<div class="nbreply"><b>' + O.esc(t("issue.my_reply", { at: m.done_at.slice(0, 16) })) + "</b><br>" + para(m.reply) + "</div>" + filesHtml(m.files) : "") +
        (canReply ? '<div class="fld"><label><b class="req">*</b>' + O.esc(t("issue.reply_to", { name: x.sender })) + '</label><textarea class="inp" id="ir_text" rows="4" maxlength="2000"></textarea><span class="em" id="ir_err"></span></div>' +
          '<div class="nbfoot"><button type="button" class="btn" id="ir_pick">' + O.icon("upload", 14) + " " + O.esc(t("issue.attach")) + '</button><span id="ir_files" class="muted" style="font-size:12.5px"></span>' +
          '<span style="flex:1"></span><button type="button" class="btn pri" id="ir_send">' + O.esc(t("issue.reply_btn")) + "</button></div>" : "") +
        (x.status === "active" && (x.need_reply ? !m.done_at : false) ? '<div class="istrail">' + O.icon("bell", 12) + " " + O.esc(t("issue.remind_info", { n: m.remind_count })) +
          (m.next_remind ? " · " + O.esc(t("issue.next_remind", { at: m.next_remind })) : "") + "</div>" : "") + "</div>";
      if (canReply) {
        document.getElementById("ir_pick").addEventListener("click", function () {
          O.pickFiles({ multiple: true }, function (fl) { replyFiles = replyFiles.concat(fl); document.getElementById("ir_files").textContent = replyFiles.map(function (f) { return f.name; }).join(t("common.list_sep")); });
        });
        document.getElementById("ir_send").addEventListener("click", function () {
          var txt = document.getElementById("ir_text").value.trim();
          document.getElementById("ir_err").textContent = "";
          if (!txt) { document.getElementById("ir_err").textContent = t("issue.err.reply_required"); return; }
          this.disabled = true;
          var btn = this;
          O.upload("/api/v1/issues/" + x.id + "/reply", replyFiles, { reply: txt }).then(function (res) {
            O.status(t(res.late ? "issue.replied_late" : "issue.replied")); O.pulse(); refreshMeta().then(function () { drawTabs(); loadIn(); openIn(x.id); });
          }, function (e) { btn.disabled = false; if (e && e.fields && e.fields.reply) document.getElementById("ir_err").textContent = e.fields.reply; else O.failed(e); });
        });
      }
      var item = list.filter(function (y) { return y.id === x.id; })[0];
      var wasUnread = item ? !item.read : !m.read_at;
      var after = function () {
        if (focusId === x.id && !item && state.filter === "pending") {
          // the item is finished or withdrawn: show the full list so it can be highlighted
          state.filter = "all";
          state.page = 1;
          O.api("GET", "/api/v1/issues", null, inQuery("all", 1)).then(function (res) { list = res.rows; lastRes = res; item = list.filter(function (y) { return y.id === x.id; })[0]; after(); }, O.failed);
          return;
        }
        if (lastRes) drawInList(lastRes);
        if (focusId === x.id) {
          focusId = 0;
          O.focusBar(t("issue.located", { title: x.title }));
          O.flash(document.querySelector('.nbi[data-id="' + x.id + '"]'));
        }
      };
      if (wasUnread && x.status === "active") {
        O.api("POST", "/api/v1/issues/" + x.id + "/read", {}).then(function () { if (item) item.read = true; O.pulse(); refreshMeta().then(drawTabs); after(); }, O.failed);
      } else {
        after();
      }
    }, function (e) { focusId = 0; O.failed(e); });
  }

  /* ---------- sent ---------- */
  function sentView() {
    var body = document.getElementById("is_body");
    body.innerHTML = '<div class="bar"><div class="chips">' + [["all", t("issue.f.all")], ["pending", t("issue.f.active")]].map(function (c) {
      return '<button type="button" class="chip' + (sstate.filter === c[0] ? " on" : "") + '" data-sf="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") +
      '</div><span class="sp"></span><button type="button" class="btn pri" id="is_new">' + O.icon("plus", 14) + " " + O.esc(t("issue.new")) + '</button></div><div class="card" id="is_card"></div>';
    var bar = body.querySelector(".bar");
    bar.insertBefore(O.filters({ state: sstate, onChange: loadSent, period: t("issue.col.at"), fields: [["title", t("issue.field.title")], ["body", t("issue.field.body")]],
      placeholder: t("issue.search") }), bar.querySelector(".sp"));
    bar.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-sf")) { sstate.filter = b.getAttribute("data-sf"); sstate.page = 1; sentView(); }
      if (b.id === "is_new") sendForm();
    });
    loadSent();
  }

  function progress(x) {
    var done = x.need_reply ? x.done : x.read;
    var pct = x.n ? Math.round(done * 100 / x.n) : 0;
    return '<span class="isprog"><span class="pbar"><i style="width:' + pct + '%"></i></span><span>' + O.esc(t(x.need_reply ? "issue.prog_done" : "issue.prog_read", { a: done, n: x.n })) + "</span></span>";
  }

  function loadSent() {
    var q = O.filterQuery(sstate);
    q.box = "sent";
    q.filter = sstate.filter;
    q.page = sstate.page;
    O.api("GET", "/api/v1/issues", null, q).then(function (res) {
      var card = document.getElementById("is_card");
      if (!card) return;
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("issue.col.title"), cls: "wrap", f: function (x) { return O.pill(t("issue.kind." + x.kind), KIND_PILL[x.kind]) + " " + O.esc(x.title) + (x.status === "withdrawn" ? " " + O.pill(t("issue.st.withdrawn")) : ""); } },
          { t: t("issue.col.due"), f: function (x) { return x.due ? '<span class="mono">' + O.esc(x.due) + "</span>" : '<span class="muted">—</span>'; } },
          { t: t("issue.col.progress"), f: progress },
          { t: t("issue.col.overdue"), cls: "num", f: function (x) { return x.overdue ? '<b style="color:var(--bad)">' + x.overdue + "</b>" : '<span class="muted">0</span>'; } },
          { t: t("issue.col.at"), f: function (x) { return '<span class="mono">' + O.esc(x.at.slice(0, 16)) + "</span>"; } }
        ],
        rows: res.rows, empty: t(sstate.kw || sstate.from || sstate.to ? "issue.none_found" : "issue.sent_empty"),
        acts: function (x) {
          var open = x.status === "active" && (x.need_reply ? x.done < x.n : x.read < x.n);
          return [
            { icon: "eye", tip: t("issue.view"), fn: function (r) { detail(r.id); } },
            open ? { icon: "bell", tip: t("issue.nudge"), fn: function (r) { nudge(r.id); } } : null,
            x.status === "active" ? { icon: "undo", tip: t("issue.withdraw"), danger: true, fn: function (r) { withdraw(r); } } : null
          ];
        }, slots: 3
      }));
      card.appendChild(O.pager(res, function (p) { sstate.page = p; loadSent(); }));
      card.insertAdjacentHTML("beforeend", O.legend([["eye", t("issue.view")], ["bell", t("issue.nudge")], ["undo", t("issue.withdraw")]]));
      if (focusId) { var f = focusId; focusId = 0; detail(f, true); }
    }, O.failed);
  }

  function detail(id, located) {
    O.api("GET", "/api/v1/issues/" + id).then(function (x) {
      if (x.role !== "sender") return;
      var el = document.createElement("div");
      el.style.display = "grid";
      el.style.gap = "12px";
      var open = x.status === "active" && x.recipients.some(function (r) { return x.need_reply ? !r.done_at : !r.read_at; });
      el.innerHTML = (x.status === "withdrawn" ? '<div class="note">' + O.esc(t("issue.withdrawn_note", { at: x.withdrawn_at.slice(0, 16) })) + "</div>" : "") +
        '<div class="muted">' + O.esc(t("issue.sent_at", { at: x.at.slice(0, 16) })) + (x.due ? " · " + O.esc(t("issue.due_at", { due: x.due })) : "") +
        (x.remind_freq !== "auto" ? " · " + O.esc(t("issue.freq." + x.remind_freq)) : "") + "</div>" +
        '<div style="line-height:1.7">' + (x.body ? para(x.body) : '<span class="muted">' + O.esc(t("issue.no_body")) + "</span>") + "</div>" + filesHtml(x.files) +
        '<div class="tw"><table class="tbl sm rftbl"><thead><tr><th>' + O.esc(t("issue.col.person")) + "</th><th>" + O.esc(t("issue.col.read")) + "</th>" +
        (x.need_reply ? "<th>" + O.esc(t("issue.col.done")) + "</th><th>" + O.esc(t("issue.col.reply")) + "</th>" : "") + "<th>" + O.esc(t("issue.col.remind")) + "</th></tr></thead><tbody>" +
        x.recipients.map(function (r) {
          return "<tr><td>" + O.esc(r.name) + (r.dept ? '<div class="muted" style="font-size:12px">' + O.esc(r.dept) + "</div>" : "") + "</td>" +
            "<td>" + (r.read_at ? '<span class="mono">' + O.esc(r.read_at.slice(5, 16)) + "</span>" : O.pill(t("issue.st.unread"), "warn")) + "</td>" +
            (x.need_reply ? "<td>" + (r.done_at ? '<span class="mono">' + O.esc(r.done_at.slice(5, 16)) + "</span>" + (r.late ? " " + O.pill(t("issue.late"), "warn") : "")
              : r.overdue ? O.pill(t("issue.st.overdue"), "bad") : '<span class="muted">' + O.esc(t("issue.st.todo")) + "</span>") + "</td>" +
              '<td class="wrap">' + (r.reply ? para(r.reply) : "") + filesHtml(r.files) + "</td>" : "") +
            '<td style="font-size:12px">' + O.esc(t("issue.remind_short", { n: r.remind_count })) + (r.nudge_count ? "<br>" + O.esc(t("issue.nudged_short", { n: r.nudge_count })) : "") +
            (r.next_remind ? '<br><span class="muted">' + O.esc(t("issue.next_remind", { at: r.next_remind })) + "</span>" : "") + "</td></tr>";
        }).join("") + "</tbody></table></div>";
      var btns = [];
      if (x.status === "active") btns.push({ t: t("issue.withdraw"), cls: "danger", fn: function () { withdraw(x); return false; } });
      if (open) btns.push({ t: t("issue.nudge"), fn: function () { nudge(x.id); return false; } });
      btns.push({ t: t("common.close") });
      O.drawer({ title: x.title, sub: t("issue.kind." + x.kind), width: 860, tabs: [{ t: x.title, el: el }], buttons: btns });
      if (located) O.focusBar(t("issue.located", { title: x.title }));
    }, O.failed);
  }

  function nudge(id) {
    O.api("POST", "/api/v1/issues/" + id + "/nudge", {}).then(function (r) {
      O.status(t("issue.nudged", { n: r.nudged }) + (r.skipped ? " " + t("issue.nudge_skipped", { n: r.skipped }) : ""));
      O.closeDrawer();
      loadSent();
    }, O.failed);
  }

  function withdraw(x) {
    O.confirm({ title: t("issue.withdraw"), msg: t("issue.withdraw_confirm", { title: x.title }), danger: true, ok: t("issue.withdraw") }).then(function (ok) {
      if (!ok) return;
      O.api("POST", "/api/v1/issues/" + x.id + "/withdraw", {}).then(function () { O.status(t("issue.withdrawn")); O.closeDrawer(); loadSent(); }, O.failed);
    });
  }

  /* ---------- send form ---------- */
  function sendForm() {
    var files = [];
    var times = [];
    var p2 = function (n) { return (n < 10 ? "0" : "") + n; };
    for (var m = 480; m <= 1110; m += 30) times.push(p2(Math.floor(m / 60)) + ":" + p2(m % 60));
    var box = document.createElement("div");
    box.style.display = "grid";
    box.style.gap = "12px";
    var f = O.form([
      { name: "title", label: t("issue.field.title"), req: true, max: 200, span2: true },
      { name: "body", label: t("issue.field.body"), type: "textarea", rows: 4, max: 5000 }
    ], {});
    box.appendChild(f.el);
    var heads = [];
    meta.groups.forEach(function (g) { g.people.forEach(function (p) { if (p.head) heads.push(p.user_id); }); });
    box.insertAdjacentHTML("beforeend",
      '<div class="fld"><label>' + O.esc(t("issue.field.files")) + '</label><div class="isrow"><button type="button" class="btn sm" id="sf_pick">' + O.icon("upload", 14) + " " + O.esc(t("issue.choose_files")) +
        '</button><span class="muted" id="sf_files" style="font-size:12.5px">' + O.esc(t("issue.files_hint")) + "</span></div></div>" +
      '<div class="fld"><label><b class="req">*</b>' + O.esc(t("issue.field.recipients")) + ' <span class="muted" id="sf_n" style="font-weight:400"></span></label>' +
        '<div class="isrow" style="margin-bottom:6px"><button type="button" class="btn sm" data-q="all">' + O.esc(t("issue.pick_all")) + "</button>" +
        (meta.wide && heads.length ? '<button type="button" class="btn sm" data-q="heads">' + O.esc(t("issue.pick_heads")) + "</button>" : "") +
        '<button type="button" class="btn sm" data-q="none">' + O.esc(t("issue.pick_none")) + "</button></div>" +
        '<div class="ispick" id="sf_people">' + (meta.groups.length ? meta.groups.map(function (g, gi) {
          return '<div class="grp"><b><label><input type="checkbox" data-g="' + gi + '"> ' + O.esc(g.dept || t("issue.no_dept")) + "</label></b>" + g.people.map(function (p) {
            return '<label><input type="checkbox" data-u="' + p.user_id + '" data-gi="' + gi + '"> ' + O.esc(p.name) + (p.head ? ' <span class="muted" style="font-size:11px">' + O.esc(t("issue.head")) + "</span>" : "") + "</label>";
          }).join("") + "</div>";
        }).join("") : '<span class="muted">' + O.esc(t("issue.no_people")) + "</span>") + '</div><span class="em" id="sf_rerr" style="color:var(--bad);font-size:12px"></span></div>' +
      '<div class="fld"><label>' + O.esc(t("issue.field.need")) + '</label><div class="opts"><label><input type="radio" name="sf_need" value="0" checked> ' + O.esc(t("issue.need.read")) +
        '</label><label><input type="radio" name="sf_need" value="1"> ' + O.esc(t("issue.need.reply")) + "</label></div></div>" +
      '<div class="fld" id="sf_duef" hidden><label><b class="req">*</b>' + O.esc(t("issue.field.due")) + '</label><div class="isrow">' + O.dateInput("sf_date", "") +
        '<select class="inp" id="sf_time">' + times.map(function (x) { return '<option' + (x === "17:30" ? " selected" : "") + ">" + x + "</option>"; }).join("") + "</select></div>" +
        '<span class="em" id="sf_derr" style="color:var(--bad);font-size:12px"></span></div>' +
      '<details class="more"><summary>' + O.esc(t("issue.more")) + '</summary><div class="fld" style="margin-top:8px"><label>' + O.esc(t("issue.field.freq")) + '</label><select class="inp" id="sf_freq" style="width:auto">' +
        meta.freqs.map(function (q) { return '<option value="' + q + '">' + O.esc(t("issue.freq." + q)) + "</option>"; }).join("") + '</select><span class="hint">' + O.esc(t("issue.freq_hint")) + "</span></div></details>");
    function picked() { return Array.prototype.map.call(box.querySelectorAll("input[data-u]:checked"), function (i) { return +i.getAttribute("data-u"); }); }
    function syncCount() { var n = picked().length; box.querySelector("#sf_n").textContent = n ? t("issue.picked", { n: n }) : ""; }
    box.addEventListener("change", function (e) {
      var g = e.target.getAttribute && e.target.getAttribute("data-g");
      if (g !== null && g !== undefined) Array.prototype.forEach.call(box.querySelectorAll('input[data-gi="' + g + '"]'), function (i) { i.checked = e.target.checked; });
      if (e.target.name === "sf_need") box.querySelector("#sf_duef").hidden = e.target.value !== "1";
      syncCount();
    });
    box.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button[data-q]") : null;
      if (!b) return;
      var q = b.getAttribute("data-q");
      Array.prototype.forEach.call(box.querySelectorAll("input[data-u]"), function (i) { i.checked = q === "all" || (q === "heads" && heads.indexOf(+i.getAttribute("data-u")) >= 0); });
      Array.prototype.forEach.call(box.querySelectorAll("input[data-g]"), function (i) { i.checked = false; });
      syncCount();
    });
    box.querySelector("#sf_pick").addEventListener("click", function () {
      O.pickFiles({ multiple: true }, function (fl) { files = files.concat(fl); box.querySelector("#sf_files").textContent = files.map(function (x) { return x.name; }).join(t("common.list_sep")); });
    });
    O.modal({ title: t("issue.new"), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("issue.send"), cls: "pri", fn: function () {
      var v = f.values();
      var need = box.querySelector('input[name="sf_need"]:checked').value;
      var fields = { title: v.title, body: v.body, need_reply: need, recipients: picked().join(","), remind_freq: box.querySelector("#sf_freq").value };
      if (need === "1") { fields.due_date = box.querySelector("#sf_date").value.trim(); fields.due_time = box.querySelector("#sf_time").value; }
      box.querySelector("#sf_rerr").textContent = "";
      box.querySelector("#sf_derr").textContent = "";
      f.errors(null);
      return O.upload("/api/v1/issues", files, fields).then(function (r) {
        O.status(t("issue.sent", { kind: t("issue.kind." + r.kind) }));
        state.tab = "sent";
        sstate.filter = "all";
        sstate.page = 1;
        render();
      }, function (e) {
        if (e && e.fields) {
          f.errors(e.fields);
          if (e.fields.recipients) box.querySelector("#sf_rerr").textContent = e.fields.recipients;
          if (e.fields.due_date) box.querySelector("#sf_derr").textContent = e.fields.due_date;
          O.status(e.message, "err");
          return Promise.reject(null);
        }
        return Promise.reject(e);
      });
    } }] });
  }

  shell();
  refreshMeta().then(render, O.failed);
})();
