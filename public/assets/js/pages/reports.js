/*
 * Comprehensive reports (phase 4, design §10): report list (default) and generation by
 * period × tasks × people. Preview shows the paper, versions, print, download and documents.
 */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var META = null;
  var st = { tab: "list", page: 1 };
  var gen = { start: "", end: "", quick: "year", allTasks: true, tasks: {}, allPeople: true, people: {} };

  function tabs() {
    return '<div class="subnav" style="border:1px solid var(--line);border-radius:4px;margin-bottom:12px" id="rp_tabs">' +
      [["list", "report.tab.list"], ["gen", "report.tab.gen"]].map(function (x) { return '<button type="button" data-tab="' + x[0] + '" class="' + (st.tab === x[0] ? "on" : "") + '">' + O.esc(t(x[1])) + "</button>"; }).join("") + "</div>";
  }
  function bindTabs() {
    document.getElementById("rp_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { st.tab = b.getAttribute("data-tab"); route(); }
    });
  }
  function route() { if (st.tab === "gen") genPage(); else listPage(); }

  /* ---------- list ---------- */
  function listPage() {
    view.innerHTML = tabs() + '<div class="bar" id="rl_bar"><span class="muted" id="rl_count"></span><span class="sp"></span>' +
      '<button type="button" class="btn" id="rl_gen">' + O.esc(t("report.tab.gen")) + '</button><button type="button" class="btn pri" id="rl_new">' + O.icon("plus", 14) + " " + O.esc(t("report.new_manual")) + "</button></div>" +
      '<div class="card" id="rl_card"></div>';
    bindTabs();
    var bar = document.getElementById("rl_bar");
    // the period keeps the reports whose own period lies inside it
    bar.insertBefore(O.filters({ state: st, onChange: loadList, period: t("report.col.period"), placeholder: t("report.search") }), bar.firstChild);
    bar.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.id === "rl_gen") { st.tab = "gen"; route(); }
      if (b.id === "rl_new") manualForm(null);
    });
    loadList();
  }

  function loadList() {
    var q = O.filterQuery(st);
    q.page = st.page;
    O.api("GET", "/api/v1/reports", null, q).then(function (res) {
      var filtered = st.from || st.to || st.kw;
      var cnt = document.getElementById("rl_count");
      if (!cnt) return;
      cnt.textContent = filtered ? t("report.count_filtered", { n: res.total, total: res.all }) : t("report.count", { n: res.all });
      var card = document.getElementById("rl_card");
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("report.col.title"), cls: "wrap", f: function (r) { return '<button type="button" class="lk" data-open="' + r.id + '" style="text-align:left;font-weight:500">' + O.esc(r.title) + "</button>"; } },
          { t: t("report.col.kind"), f: function (r) { return O.esc(t("report.kind." + r.kind)); } },
          { t: t("report.col.period"), f: function (r) { return '<span class="mono">' + O.esc(r.start ? r.start + " – " + r.end : "—") + "</span>"; } },
          { t: t("report.col.docs"), cls: "num", f: function (r) { return r.docs ? String(r.docs) : "—"; } },
          { t: t("report.col.version"), f: function (r) { return "v" + r.version; } },
          { t: t("report.col.updated"), f: function (r) { return '<span class="mono">' + O.esc(r.updated_at.slice(0, 16)) + "</span>"; } }
        ],
        rows: res.rows, empty: filtered ? t("report.empty_filtered") : t("report.empty"),
        acts: function () {
          return [
            { icon: "eye", tip: t("report.preview"), fn: function (x) { preview(x.id); } },
            { icon: "upload", tip: t("report.upload"), fn: function (x) { uploadDocs(x.id, loadList); } },
            { icon: "edit", tip: t("common.edit"), fn: function (x) { O.api("GET", "/api/v1/reports/" + x.id).then(function (d) { editForm(d, loadList); }, O.failed); } },
            { icon: "trash", tip: t("common.delete"), danger: true, fn: function (x) { remove(x, loadList); } }
          ];
        }, slots: 4
      }));
      card.appendChild(O.pager(res, function (p) { st.page = p; loadList(); }));
      if (filtered && !res.rows.length && res.all) card.insertAdjacentHTML("beforeend", '<div class="card-b"><button type="button" class="btn" id="rl_all">' + O.esc(t("report.show_all")) + "</button></div>");
      card.insertAdjacentHTML("beforeend", O.legend([["eye", t("report.preview")], ["upload", t("report.upload")], ["edit", t("common.edit")], ["trash", t("common.delete")]]) +
        '<div class="card-b muted" style="font-size:12.5px;padding-top:0">' + O.esc(t("report.list_hint")) + "</div>");
      card.onclick = function (e) {
        var op = e.target.closest ? e.target.closest("[data-open]") : null;
        if (op) preview(+op.getAttribute("data-open"));
        if (e.target.id === "rl_all") { st.period = "all"; st.from = ""; st.to = ""; st.kw = ""; st.page = 1; listPage(); }
      };
    }, O.failed);
  }

  /* ---------- generate ---------- */
  function setQuick(k) {
    gen.quick = k;
    var r = META.ranges[k];
    if (r) { gen.start = r[0]; gen.end = r[1]; }
  }

  function genPage() {
    if (!gen.start) setQuick("year");
    view.innerHTML = tabs() + '<div class="split" style="grid-template-columns:340px minmax(0,1fr)"><div class="card rcfg"><div class="card-h">' + O.esc(t("report.cond")) + '</div><div class="card-b" id="rg_cfg" style="display:grid;gap:14px"></div></div>' +
      '<div class="card"><div class="card-b muted" id="rg_help"></div></div></div>';
    bindTabs();
    document.getElementById("rg_help").innerHTML = "<p>" + O.esc(t("report.gen_help1")) + "</p><p>" + O.esc(t("report.gen_help2")) + "</p><p>" + O.esc(t("report.gen_help3")) + "</p>";
    drawCfg();
  }

  function drawCfg() {
    var cfg = document.getElementById("rg_cfg");
    var quick = [["week", "plan.per.week"], ["month", "plan.per.month"], ["quarter", "report.per.quarter"], ["year", "plan.per.year"]];
    var html = '<div class="fld"><label>' + O.esc(t("report.field.period")) + '</label><div style="display:flex;gap:6px;align-items:center">' + O.dateInput("rg_s", gen.start, ' style="width:120px"') + '<span class="muted">—</span>' + O.dateInput("rg_e", gen.end, ' style="width:120px"') + "</div>" +
      '<div class="chips" style="margin-top:6px">' + quick.map(function (q) { return '<button type="button" class="chip' + (gen.quick === q[0] ? " on" : "") + '" data-quick="' + q[0] + '">' + O.esc(t(q[1])) + "</button>"; }).join("") + '</div><span class="em" id="rg_err_end"></span></div>';
    // tasks
    var nTasks = Object.keys(gen.tasks).length;
    html += '<div class="fld"><label>' + O.esc(t("report.field.tasks")) + '</label><div class="opts"><label><input type="radio" name="rg_ta" value="all"' + (gen.allTasks ? " checked" : "") + "> " + O.esc(t("report.all_tasks")) +
      '</label><label><input type="radio" name="rg_ta" value="pick"' + (gen.allTasks ? "" : " checked") + "> " + O.esc(t("report.pick_tasks")) + "</label></div>";
    if (!gen.allTasks) {
      html += '<div class="rlist">' + (META.tasks.length ? META.tasks.map(function (x) {
        return '<label style="padding-left:' + (x.depth * 14) + 'px"><input type="checkbox" data-task="' + x.id + '"' + (gen.tasks[x.id] ? " checked" : "") + "> " + O.plan.levelPill(x.level) + " " + O.esc(x.title) + "</label>";
      }).join("") : '<span class="muted">' + O.esc(t("plan.empty")) + "</span>") + '</div><span class="muted" style="font-size:12px">' + O.esc(t("report.picked_tasks", { n: nTasks })) + "</span>";
    }
    html += '<span class="em" id="rg_err_plan_ids"></span></div>';
    // people
    html += '<div class="fld"><label>' + O.esc(t("report.field.people")) + "</label>";
    if (META.fixed_person) {
      html += '<div class="ro">' + O.esc(META.people.length ? META.people[0].name : "—") + '</div><span class="hint">' + O.esc(t("report.self_only")) + "</span>";
    } else {
      var nPeople = Object.keys(gen.people).length;
      html += '<div class="opts"><label><input type="radio" name="rg_pa" value="all"' + (gen.allPeople ? " checked" : "") + "> " + O.esc(t("report.all_people")) +
        '</label><label><input type="radio" name="rg_pa" value="pick"' + (gen.allPeople ? "" : " checked") + "> " + O.esc(t("report.pick_people")) + "</label></div>";
      if (!gen.allPeople) {
        var groups = [], by = {};
        META.people.forEach(function (p) { var k = p.dept || t("plan.no_dept"); if (!by[k]) { by[k] = []; groups.push(k); } by[k].push(p); });
        html += '<div class="rlist">' + groups.map(function (g) {
          return '<div class="muted" style="font-size:12px;margin-top:4px">' + O.esc(g) + ' <button type="button" class="btn link" data-grp="' + O.esc(g) + '">' + O.esc(t("report.select_dept")) + "</button></div>" +
            by[g].map(function (p) { return '<label><input type="checkbox" data-person="' + p.id + '"' + (gen.people[p.id] ? " checked" : "") + "> " + O.esc(p.name) + "</label>"; }).join("");
        }).join("") + '</div><span class="muted" style="font-size:12px">' + O.esc(t("report.picked_people", { n: nPeople })) + "</span>";
      }
      html += '<span class="em" id="rg_err_person_ids"></span>';
    }
    html += '</div><button type="button" class="btn pri" id="rg_go">' + O.esc(t("report.generate")) + "</button>";
    cfg.innerHTML = html;
    cfg.onclick = function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-quick")) { setQuick(b.getAttribute("data-quick")); drawCfg(); }
      if (b.hasAttribute("data-grp")) {
        var g = b.getAttribute("data-grp");
        META.people.forEach(function (p) { if ((p.dept || t("plan.no_dept")) === g) gen.people[p.id] = true; });
        drawCfg();
      }
      if (b.id === "rg_go") generate(b);
    };
    cfg.onchange = function (e) {
      var x = e.target;
      if (x.id === "rg_s") { gen.start = x.value; gen.quick = ""; drawCfg(); }
      if (x.id === "rg_e") { gen.end = x.value; gen.quick = ""; drawCfg(); }
      if (x.name === "rg_ta") { gen.allTasks = x.value === "all"; drawCfg(); }
      if (x.name === "rg_pa") { gen.allPeople = x.value === "all"; drawCfg(); }
      if (x.hasAttribute("data-task")) { if (x.checked) gen.tasks[x.getAttribute("data-task")] = true; else delete gen.tasks[x.getAttribute("data-task")]; drawCfg(); }
      if (x.hasAttribute("data-person")) { if (x.checked) gen.people[x.getAttribute("data-person")] = true; else delete gen.people[x.getAttribute("data-person")]; drawCfg(); }
    };
  }

  function generate(btn) {
    var body = { start: gen.start, end: gen.end };
    if (!gen.allTasks) body.plan_ids = Object.keys(gen.tasks).map(Number);
    if (!gen.allPeople && !META.fixed_person) body.person_ids = Object.keys(gen.people).map(Number);
    btn.disabled = true;
    O.api("POST", "/api/v1/reports/generate", body).then(function (r) {
      O.status(t("report.generated"));
      preview(r.id);
    }, function (err) {
      btn.disabled = false;
      if (err && err.fields) {
        Object.keys(err.fields).forEach(function (k) { var el = document.getElementById("rg_err_" + (k === "start" ? "end" : k)); if (el) el.textContent = err.fields[k]; });
        O.status(err.message, "err");
        return;
      }
      O.failed(err);
    });
  }

  /* ---------- paper ---------- */
  function para(s) { return O.esc(s || "").replace(/\n/g, "<br>"); }
  function pct(v) { return (Math.round((+v || 0) * 10) / 10) + "%"; }
  function itemHtml(it) {
    return '<div style="padding-left:16px;font-size:13px">' + O.pill(O.plan.label(it.plan), "ok") + " <b>" + O.esc(it.person) + O.esc(t("common.colon")) + "</b>" + para(it.done) +
      (it.problems ? '<div class="muted" style="padding-left:4px">' + O.esc(t("report.problems", { text: it.problems })) + "</div>" : "") + "</div>";
  }
  function paperHtml(d) {
    var h = '<h3 class="ptitle">' + O.esc(d.title) + "</h3>";
    if (d.kind === "manual") {
      if (d.start) h += '<div class="pmeta">' + O.esc(t("report.period", { start: d.start, end: d.end })) + "</div>";
      h += d.body ? '<div style="line-height:1.8">' + para(d.body) + "</div>" : '<p class="muted">' + O.esc(t("report.body_empty")) + "</p>";
      return h;
    }
    var c = d.content, sep = t("common.list_sep");
    h += '<div class="pmeta">' + O.esc(c.tasks === null ? t("report.meta_all_tasks") : t("report.meta_tasks", { list: c.tasks.join(sep) })) + " · " +
      O.esc(c.people === null ? t("report.meta_all_people") : t("report.meta_people", { list: c.people.join(sep) })) + "</div>";
    h += '<div class="rtxt">' + (d.intro ? para(d.intro) : O.esc(t("report.default_intro", { start: c.start, end: c.end, tasks: c.stats.tasks, items: c.stats.items }))) + "</div>";
    if (!c.sections.length) h += '<p class="muted">' + O.esc(t("report.no_sections")) + "</p>";
    c.sections.forEach(function (s, i) {
      h += '<div class="rsec">' + O.esc(t("report.sec_head", { n: i + 1, title: s.plan.title })) + "<em>" + O.esc(t("report.sec_meta", { scope: s.plan.dept || t("plan.unit"), pct: pct(s.progress).replace("%", "") })) + "</em></div>";
      if (s.own.length) h += '<div class="muted" style="font-size:12.5px">' + O.esc(t("report.own_results")) + "</div>" + s.own.map(itemHtml).join("");
      s.children.forEach(function (ch, j) {
        var meta = t("report.child_meta", { people: ch.assignees.join(sep), pct: pct(ch.progress).replace("%", "") });
        h += '<div style="display:grid;gap:3px;padding-left:8px"><b>' + O.esc(t("report.child_head", { n: j + 1, title: ch.plan.title })) + ' <span class="muted mono" style="font-weight:400">' + O.esc(O.plan.label(ch.plan)) + "</span></b>" +
          '<span class="muted" style="font-size:12.5px">' + O.esc(meta) + (ch.own.length ? "" : " · " + O.esc(t(ch.below.length ? "report.no_own_below" : "report.no_results"))) + "</span>" +
          (ch.own.length ? ch.own : ch.below).map(itemHtml).join("") + "</div>";
      });
      if (!s.own.length && !s.children.length) h += '<p class="muted">' + O.esc(t("report.no_results")) + "</p>";
    });
    if (d.outro) h += '<div class="rtxt">' + para(d.outro) + "</div>";
    h += '<div class="pfoot"><span>' + O.esc(t("report.as_of", { time: d.as_of })) + "</span><span>v" + d.version + "</span></div>";
    return h;
  }

  /* ---------- preview ---------- */
  function preview(id, v) {
    O.api("GET", "/api/v1/reports/" + id, null, { v: v }).then(function (d) {
      var old = d.version !== d.current;
      view.innerHTML = '<div class="card"><div class="rtool">' +
        '<button type="button" class="btn" id="pv_back">' + O.icon("back", 14) + " " + O.esc(t("report.back")) + "</button>" +
        "<b>v" + d.version + "</b>" + (old ? O.pill(t("report.old_version", { n: d.current }), "warn") : "") + (d.as_of ? '<span class="muted">' + O.esc(t("report.as_of", { time: d.as_of })) + "</span>" : "") +
        '<span class="sp"></span>' +
        (old ? "" : '<button type="button" class="btn" id="pv_edit">' + O.icon("edit", 14) + " " + O.esc(t("common.edit")) + "</button>") +
        (d.versions.length > 1 ? '<button type="button" class="btn" id="pv_ver">' + O.esc(t("report.versions")) + " ▾</button>" : "") +
        (META.can_export ? '<button type="button" class="btn" id="pv_print">' + O.esc(t("report.print")) + '</button><button type="button" class="btn pri" id="pv_dl">' + O.icon("download", 14) + " " + O.esc(t("report.download")) + " ▾</button>" : "") +
        '<button type="button" class="btn danger" id="pv_del">' + O.icon("trash", 14) + " " + O.esc(t("common.delete")) + "</button>" +
        '</div><div class="paperwrap"><div class="paper" id="pv_paper">' + paperHtml(d) + "</div></div></div>" +
        '<div class="card"><div class="card-h">' + O.esc(t("report.docs", { n: d.docs.length })) + '<span class="r"><button type="button" class="btn sm" id="pv_up">' + O.icon("upload", 14) + " " + O.esc(t("report.upload")) + "</button></span></div>" +
        '<div class="card-b" id="pv_docs">' + docsHtml(d.docs) + "</div></div>";
      view.querySelector(".rtool").addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("button") : null;
        if (!b) return;
        if (b.id === "pv_back") { st.tab = "list"; route(); }
        if (b.id === "pv_edit") editForm(d, function () { preview(id); });
        if (b.id === "pv_ver") O.dropdown(b, d.versions.map(function (x) { return { t: t("report.version_item", { n: x.no, at: x.at.slice(0, 16), by: x.by || "—" }), on: x.no === d.version, fn: function () { preview(id, x.no === d.current ? null : x.no); } }; }));
        if (b.id === "pv_print") printPaper(d);
        if (b.id === "pv_dl") O.docChoices("report").then(function (list) {
          var dl = function (q) { window.location.href = O.url("/api/v1/reports/" + id + "/download", Object.assign({ v: old ? d.version : "" }, q)); };
          var items = list.length > 1 ? list.map(function (c) { return { t: t("report.dl_word_tpl", { name: c.name }), fn: function () { dl({ format: "docx", tpl: c.id }); } }; })
            : [{ t: t("report.dl_word"), fn: function () { dl({ format: "docx" }); } }];
          if (d.kind === "gen") items.push({ t: t("report.dl_excel"), fn: function () { dl({ format: "xlsx" }); } });
          O.dropdown(b, items);
        }, O.failed);
        if (b.id === "pv_del") remove(d, function () { st.tab = "list"; route(); });
      });
      document.getElementById("pv_up").addEventListener("click", function () { uploadDocs(id, function () { preview(id, v); }); });
      document.getElementById("pv_docs").addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-delfile]") : null;
        if (!b) return;
        O.confirm({ title: t("report.doc_delete"), msg: t("report.doc_delete_confirm"), ok: t("common.delete"), danger: true }).then(function (ok) {
          if (ok) O.api("DELETE", "/api/v1/files/" + b.getAttribute("data-delfile")).then(function () { O.status(t("common.deleted")); preview(id, v); }, O.failed);
        });
      });
    }, O.failed);
  }

  function docsHtml(docs) {
    if (!docs.length) return '<span class="muted">' + O.esc(t("report.no_docs")) + "</span>";
    return '<div class="isfiles">' + docs.map(function (f) {
      return '<div class="isf"><span class="grow"><a class="lk" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '">' + O.esc(f.name) + '</a> <span class="muted" style="font-size:12px">' + O.esc(O.fileSize(f.size) + " · " + f.at.slice(0, 16)) + "</span></span>" +
        (f.ext === "pdf" || f.image ? '<a class="ib" target="_blank" rel="noopener" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id, { inline: 1 })) + '" title="' + O.esc(t("file.preview")) + '">' + O.icon("eye") + "</a>" : "") +
        '<button type="button" class="ib danger" data-delfile="' + f.relation_id + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button></div>";
    }).join("") + "</div>";
  }

  function printPaper(d) {
    var area = document.createElement("div");
    area.id = "printArea";
    area.className = "printpage";
    area.innerHTML = '<div class="paper">' + paperHtml(d) + "</div>";
    document.body.appendChild(area);
    document.body.classList.add("printing");
    var done = false;
    var cleanup = function () {
      if (done) return;
      done = true;
      document.body.classList.remove("printing");
      if (area.parentNode) area.parentNode.removeChild(area);
    };
    window.addEventListener("afterprint", cleanup, { once: true });
    O.api("POST", "/api/v1/reports/" + d.id + "/printed", {}, { v: d.version !== d.current ? d.version : "" }).then(null, function () { /* the log is best effort */ });
    window.print();
    setTimeout(cleanup, 1000);
  }

  /* ---------- forms ---------- */
  function uploadDocs(id, done) {
    O.pickFiles({ multiple: true }, function (files) {
      O.upload("/api/v1/reports/" + id + "/files", files).then(function () { O.status(t("file.uploaded", { n: files.length })); if (done) done(); }, O.failed);
    });
  }

  function editForm(d, done) {
    var f;
    if (d.kind === "gen") {
      var c = d.content;
      f = O.form([
        { name: "title", label: t("report.field.title"), max: 200, span2: true, placeholder: t("report.default_title", { start: d.start, end: d.end }) },
        { name: "intro", label: t("report.field.intro"), type: "textarea", rows: 4, max: 5000, hint: t("report.intro_hint") },
        { name: "outro", label: t("report.field.outro"), type: "textarea", rows: 4, max: 5000 }
      ], { title: d.title_custom, intro: d.intro, outro: d.outro });
      var ta = f.el.querySelector('[name="intro"]');
      ta.setAttribute("placeholder", t("report.default_intro", { start: c.start, end: c.end, tasks: c.stats.tasks, items: c.stats.items }));
      f.el.insertAdjacentHTML("afterbegin", '<p class="muted span2" style="margin:0 0 6px;font-size:12.5px">' + O.esc(t("report.edit_gen_hint")) + "</p>");
    } else {
      f = O.form([
        { name: "title", label: t("report.field.name"), req: true, max: 200, span2: true },
        { name: "start", label: t("report.field.start"), type: "date", req: true },
        { name: "end", label: t("report.field.end"), type: "date", req: true },
        { name: "body", label: t("report.field.body"), type: "textarea", rows: 12, max: 50000 }
      ], { title: d.title, start: d.start, end: d.end, body: d.body });
    }
    O.modal({ title: t("report.edit"), width: 760, sticky: true, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var body = f.values();
      body.version = d.current;
      return O.submit(f, O.api("PUT", "/api/v1/reports/" + d.id, body)).then(function (r) { O.status(t("report.saved", { n: r.version })); if (done) done(); });
    } }] });
  }

  function manualForm() {
    var m = META.ranges.month;
    var f = O.form([
      { name: "title", label: t("report.field.name"), req: true, max: 200, span2: true },
      { name: "start", label: t("report.field.start"), type: "date", req: true },
      { name: "end", label: t("report.field.end"), type: "date", req: true },
      { name: "body", label: t("report.field.body"), type: "textarea", rows: 10, max: 50000, hint: t("report.body_hint") }
    ], { start: m[0], end: m[1] });
    var files = [];
    var fl = document.createElement("div");
    fl.className = "fld";
    fl.style.marginTop = "10px";
    function drawFiles() {
      fl.innerHTML = "<label>" + O.esc(t("report.docs_label")) + "</label>" + (files.length ? '<div class="isfiles">' + files.map(function (x, i) {
        return '<div class="isf"><span class="grow">' + O.esc(x.name) + ' <span class="muted" style="font-size:12px">' + O.esc(O.fileSize(x.size)) + '</span></span><button type="button" class="ib danger" data-rm="' + i + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button></div>";
      }).join("") + "</div>" : "") + '<div style="margin-top:6px"><button type="button" class="btn sm" data-pick="1">' + O.icon("upload", 14) + " " + O.esc(t("file.choose")) + "</button></div>";
    }
    drawFiles();
    fl.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-pick")) O.pickFiles({ multiple: true }, function (list) { files = files.concat(list); drawFiles(); });
      if (b.hasAttribute("data-rm")) { files.splice(+b.getAttribute("data-rm"), 1); drawFiles(); }
    });
    var box = document.createElement("div");
    box.appendChild(f.el);
    box.appendChild(fl);
    O.modal({ title: t("report.new_manual"), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      return O.submit(f, O.upload("/api/v1/reports/manual", files, f.values())).then(function (r) { O.status(t("common.saved")); preview(r.id); });
    } }] });
  }

  function remove(r, done) {
    O.confirm({ title: t("report.delete"), msg: t("report.delete_confirm", { title: r.title }), ok: t("common.delete"), danger: true }).then(function (ok) {
      if (!ok) return;
      O.api("DELETE", "/api/v1/reports/" + r.id).then(function () { O.status(t("common.deleted")); if (done) done(); }, O.failed);
    });
  }

  O.api("GET", "/api/v1/reports/meta").then(function (m) {
    META = m;
    return O.plan.meta().then(function () { route(); });
  }, O.failed).then(null, O.failed);
})();
