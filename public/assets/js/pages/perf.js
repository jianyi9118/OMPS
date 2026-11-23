/*
 * Results helpers (phase 4), shared by "My tasks", "Plans & results", plan details and review:
 * OMPS.perf.write(planId, done)   the write dialog (my result on a task assigned to me)
 * OMPS.perf.view(planId, done)    results of every assignee on a plan
 * OMPS.perf.listEl(data, done)    the same as an element (plan details tab)
 * OMPS.perf.history(reportId)     every save, submission and review of one result
 * OMPS.perf.statusPill(status) / progress(pct) / filesHtml(files, canDelete)
 */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var KIND = { none: "", draft: "", submitted: "info", approved: "ok", returned: "bad", withdrawn: "warn" };

  function statusPill(st) { return O.pill(t("perf.status." + st), KIND[st] || ""); }
  function pct(v) { return v === null || v === undefined ? "" : (Math.round(v * 10) / 10) + "%"; }
  function progress(v) {
    var x = Math.max(0, Math.min(100, +v || 0));
    return '<span class="isprog"><span class="pbar"><i style="width:' + x + '%"></i></span><span class="num">' + O.esc(pct(x)) + "</span></span>";
  }
  function para(s) { return O.esc(s || "").replace(/\n/g, "<br>"); }

  function filesHtml(files, canDelete) {
    if (!files || !files.length) return "";
    return '<div class="isfiles">' + files.map(function (f) {
      return '<div class="isf"><span class="grow"><a class="lk" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '">' + O.esc(f.name) + '</a> <span class="muted" style="font-size:12px">' + O.esc(O.fileSize(f.size)) + "</span></span>" +
        (f.image ? '<a class="ib" target="_blank" rel="noopener" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id, { inline: 1 })) + '" title="' + O.esc(t("file.preview")) + '">' + O.icon("eye") + "</a>" : "") +
        (canDelete ? '<button type="button" class="ib danger" data-delfile="' + f.relation_id + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button>" : "") + "</div>";
    }).join("") + "</div>";
  }

  /* ---------- write ---------- */
  function write(planId, done) {
    return O.api("GET", "/api/v1/perf/plans/" + planId + "/mine").then(function (d) {
      var r = d.report, p = d.plan;
      var editable = d.editable;
      var box = document.createElement("div");
      box.style.display = "grid";
      box.style.gap = "12px";
      var html = "";
      if (d.sub.length) {
        html += '<div class="box-ro"><b>' + O.esc(t("perf.sub_title")) + "</b>" + d.sub.map(function (s) {
          return "<div>" + O.pill(O.plan.label(s.plan), "ok") + " " + O.esc(s.plan.title) + " " + statusPill(s.status) + (s.progress !== null ? ' <span class="muted num">' + O.esc(pct(s.progress)) + "</span>" : "") +
            (s.done ? '<div class="muted" style="margin:2px 0 0 4px">' + para(s.done) + "</div>" : "") + "</div>";
        }).join("") + (editable ? '<div><button type="button" class="btn link" data-quote="1">' + O.esc(t("perf.quote")) + "</button></div>" : "") + "</div>";
      }
      if (r && r.status !== "draft") {
        html += '<div class="note' + (r.status === "returned" ? " bad" : "") + '">' + statusPill(r.status) + " " +
          O.esc(t("perf.state_note." + r.status, { at: r.reviewed_at || r.submitted_at })) + (r.comment ? "<br><b>" + O.esc(t(r.status === "returned" ? "perf.reason" : "perf.field.comment")) + "</b>" + O.esc(t("common.colon")) + para(r.comment) : "") + "</div>";
      }
      if (d.self_approves && editable) html += '<div class="muted" style="font-size:12.5px">' + O.esc(t("perf.self_approves")) + "</div>";
      box.innerHTML = html;
      var vals = r ? { progress: r.progress === null ? "" : String(r.progress), done: r.done, problems: r.problems, next: r.next } : { progress: "", done: "", problems: "", next: "" };
      if (!vals.done && d.sub.length) vals.done = quoteText(d.sub);
      var f;
      if (editable) {
        f = O.form([
          { name: "progress", label: t("perf.field.progress"), max: 6, hint: d.reference !== null ? t("perf.reference", { pct: pct(d.reference) }) : t("perf.progress_hint") },
          { name: "done", label: t("perf.field.done"), type: "textarea", rows: 5, max: 5000, req: true, hint: d.sub.length ? t("perf.done_hint") : "" },
          { name: "problems", label: t("perf.field.problems"), type: "textarea", rows: 2, max: 2000 },
          { name: "next", label: t("perf.field.next"), type: "textarea", rows: 2, max: 2000 }
        ], vals);
        box.appendChild(f.el);
        if (d.reference !== null) {
          var pf = f.el.querySelector('[data-f="progress"] .hint');
          pf.insertAdjacentHTML("beforeend", ' <button type="button" class="btn link" data-use="1">' + O.esc(t("perf.use_reference")) + "</button>");
        }
        var pin = f.el.querySelector('[name="progress"]');
        pin.setAttribute("inputmode", "decimal");
        pin.style.maxWidth = "120px";
      } else {
        box.insertAdjacentHTML("beforeend", '<div class="fgrid">' +
          '<div class="fld"><label>' + O.esc(t("perf.field.progress")) + '</label><div class="ro">' + progress(r.progress) + "</div></div>" +
          '<div class="fld span2"><label>' + O.esc(t("perf.field.done")) + '</label><div class="ro">' + para(r.done) + "</div></div>" +
          (r.problems ? '<div class="fld span2"><label>' + O.esc(t("perf.label.problems")) + '</label><div class="ro">' + para(r.problems) + "</div></div>" : "") +
          (r.next ? '<div class="fld span2"><label>' + O.esc(t("perf.label.next")) + '</label><div class="ro">' + para(r.next) + "</div></div>" : "") + "</div>");
      }
      var filesEl = document.createElement("div");
      filesEl.className = "fld";
      box.appendChild(filesEl);
      var files = d.files, reportId = r ? r.id : null;
      var canDel = function () { return editable && (!r || r.status !== "approved"); };
      function drawFiles() {
        filesEl.innerHTML = "<label>" + O.esc(t("perf.files")) + "</label>" + (filesHtml(files, canDel()) || '<span class="muted">' + O.esc(t("file.none")) + "</span>") +
          (editable ? '<div style="margin-top:6px"><button type="button" class="btn sm" data-up="1">' + O.icon("upload", 14) + " " + O.esc(t("perf.add_files")) + "</button></div>" : "");
      }
      drawFiles();
      box.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("button") : null;
        if (!b) return;
        if (b.hasAttribute("data-quote")) {
          var ta = box.querySelector('[name="done"]');
          ta.value = (ta.value ? ta.value.replace(/\s+$/, "") + "\n" : "") + quoteText(d.sub);
        }
        if (b.hasAttribute("data-use")) box.querySelector('[name="progress"]').value = String(d.reference);
        if (b.hasAttribute("data-up")) {
          O.pickFiles({ multiple: true }, function (list) {
            O.upload("/api/v1/perf/plans/" + planId + "/mine/files", list).then(function (res) {
              files = res.files;
              reportId = res.report_id;
              drawFiles();
              O.status(t("file.uploaded", { n: list.length }));
            }, O.failed);
          });
        }
        if (b.hasAttribute("data-delfile")) {
          O.api("DELETE", "/api/v1/files/" + b.getAttribute("data-delfile")).then(function () {
            var id = +b.getAttribute("data-delfile");
            files = files.filter(function (x) { return x.relation_id !== id; });
            drawFiles();
            O.status(t("common.deleted"));
          }, O.failed);
        }
      });
      function send(submit) {
        var v = f.values();
        var body = { progress: v.progress, done: v.done, problems: v.problems, next: v.next, submit: submit };
        return O.submit(f, O.api("PUT", "/api/v1/perf/plans/" + planId + "/mine", body)).then(function (res) {
          O.status(t(res.status === "approved" ? "perf.saved_approved" : (submit ? "perf.saved_submitted" : "perf.saved_draft")));
          if (done) done();
        });
      }
      var buttons = [{ t: t("common.close") }];
      if (r) buttons.unshift({ t: t("perf.history"), fn: function () { history(r.id); return false; } });
      if (editable) {
        buttons = [{ t: t("common.cancel") }].concat(r ? [{ t: t("perf.history"), fn: function () { history(r.id); return false; } }] : []).concat([
          { t: t("common.save"), fn: function () { return send(false); } },
          { t: t(d.self_approves ? "perf.submit_direct" : "perf.submit"), cls: "pri", fn: function () { return send(true); } }]);
      } else if (r && r.status === "submitted") {
        buttons.splice(buttons.length - 1, 0, { t: t("perf.withdraw"), fn: function () {
          return O.api("POST", "/api/v1/perf/reports/" + r.id + "/withdraw", {}).then(function () { O.status(t("perf.withdrawn")); if (done) done(); });
        } });
      }
      O.modal({ title: t("perf.write_title", { title: p.title }), width: 760, body: box, sticky: editable, buttons: buttons, onClose: function () { if (reportId && !r && done) done(); } });
    }, O.failed);
  }
  function quoteText(sub) {
    return sub.filter(function (s) { return s.done; }).map(function (s) { return "[" + O.plan.label(s.plan) + "] " + s.done.replace(/\s+$/, ""); }).join("\n");
  }

  /* ---------- results of a plan ---------- */
  function listEl(d, done) {
    var el = document.createElement("div");
    el.innerHTML = '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px"><b>' + O.esc(t("perf.plan_progress")) + "</b>" + progress(d.progress) + '<span class="muted" style="font-size:12px">' + O.esc(t("perf.progress_rule")) + "</span></div>" +
      (d.rows.length ? d.rows.map(function (x) {
        var s = x.shown, head = '<div style="display:flex;gap:8px;align-items:center"><b>' + O.esc(x.person) + "</b>" + statusPill(x.status) + (s && s.progress !== null ? " " + progress(s.progress) : "") +
          '<span class="sp" style="flex:1"></span>' + (x.id && (x.own || s) ? '<button type="button" class="btn link" data-hist="' + x.id + '">' + O.esc(t("perf.history")) + "</button>" : "") +
          (x.own && done ? '<button type="button" class="btn sm" data-write="1">' + O.icon("write", 14) + " " + O.esc(t(x.status === "submitted" ? "perf.view_mine" : "perf.write")) + "</button>" : "") + "</div>";
        var body = "";
        if (s) {
          if (s.approved_only) body += '<div class="muted" style="font-size:12px">' + O.esc(t("perf.approved_only")) + "</div>";
          body += s.done ? "<div>" + para(s.done) + "</div>" : "";
          if (s.problems) body += '<div><span class="muted">' + O.esc(t("perf.label.problems")) + O.esc(t("common.colon")) + "</span>" + para(s.problems) + "</div>";
          if (s.next) body += '<div><span class="muted">' + O.esc(t("perf.label.next")) + O.esc(t("common.colon")) + "</span>" + para(s.next) + "</div>";
          body += filesHtml(x.files, false);
          if (x.reviewed_at && (x.status === "approved" || x.status === "returned")) {
            body += '<div class="muted" style="font-size:12px">' + O.esc(t(x.status === "approved" ? (x.edited ? "perf.reviewed_edited" : "perf.reviewed_ok") : "perf.reviewed_returned", { at: x.reviewed_at })) +
              (x.score !== null ? " · " + O.esc(t("perf.score_is", { score: x.score })) : "") + (x.comment ? " · " + O.esc(x.comment) : "") + "</div>";
          }
        } else if (x.status !== "none") {
          body += '<div class="muted">' + O.esc(t("perf.not_shared")) + "</div>";
        }
        return '<div class="box-ro" style="margin-bottom:8px">' + head + body + "</div>";
      }).join("") : '<p class="muted">' + O.esc(t("perf.no_assignees")) + "</p>");
    el.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-hist")) history(+b.getAttribute("data-hist"));
      if (b.hasAttribute("data-write")) write(d.plan.id, done);
    });
    return el;
  }

  function view(planId, done) {
    return O.api("GET", "/api/v1/perf/plans/" + planId).then(function (d) {
      var m = O.modal({ title: t("perf.view_title", { title: d.plan.title }), width: 760, body: listEl(d, function () { m.close(); if (done) done(); }) });
    }, O.failed);
  }

  function history(id) {
    O.api("GET", "/api/v1/perf/reports/" + id + "/history").then(function (h) {
      var el = O.table({
        cols: [
          { t: t("perf.h.when"), f: function (v) { return '<span class="mono">' + O.esc(v.at) + "</span>"; } },
          { t: t("perf.h.by"), f: function (v) { return O.esc(v.by || "—"); } },
          { t: t("perf.h.kind"), f: function (v) { return O.esc(t("perf.kind." + v.kind)); } },
          { t: t("perf.field.progress"), cls: "num", f: function (v) { return O.esc(pct(v.data.progress)); } },
          { t: t("perf.field.done"), cls: "wrap", f: function (v) { return para(v.data.done) + (v.data.comment ? '<div class="muted">' + O.esc(v.data.comment) + "</div>" : ""); } }
        ], rows: h.rows, empty: t("common.empty")
      });
      O.modal({ title: t("perf.history"), width: 820, body: el });
    }, O.failed);
  }

  O.perf = { write: write, view: view, listEl: listEl, history: history, statusPill: statusPill, progress: progress, pct: pct, filesHtml: filesHtml, para: para };
})();
