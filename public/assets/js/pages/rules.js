/* 提醒规则 (phase 5, design §13.3): triggers picked from drop-downs; the text and concrete example times are generated */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var data = null;
  var LEVEL_PILL = { info: "", warn: "warn", urgent: "bad" };

  function pad(n) { return (n < 10 ? "0" : "") + n; }
  var TIMES = [];
  for (var m = 0; m < 1440; m += 30) TIMES.push(pad(Math.floor(m / 60)) + ":" + pad(m % 60));

  /* text of one trigger, in the current language */
  function say(rule, x) {
    var base = t("rule.base." + rule.code);
    var tm = x.time_of_day ? " " + x.time_of_day : "";
    switch (x.mode) {
      case "before": return t("rule.say.before", { base: base, n: x.amount, unit: t("rule.unit." + x.unit) }) + tm;
      case "onday": return t("rule.say.onday", { base: base, time: x.time_of_day });
      case "after": return x.repeat_mode === "once" ? t("rule.say.after_once", { n: x.amount, time: x.time_of_day }) : t("rule.say.after_" + x.repeat_mode, { time: x.time_of_day });
      case "now": return t("rule.say.now", { base: base });
      case "evt": return t("rule.say.evt", { base: base, n: x.amount, unit: t("rule.unit." + x.unit) }) + tm;
      case "wday": return t("rule.say.wday", { time: x.time_of_day });
      case "week": return t("rule.say.week", { wd: t("common.wd" + (x.weekday % 7)), time: x.time_of_day });
      case "mlast": return t("rule.say.mlast", { time: x.time_of_day });
      case "mday": return t("rule.say.mday", { n: x.month_day, time: x.time_of_day });
    }
    return "";
  }

  function workText(w) {
    var days = w.days.map(function (d) { return t("common.wd" + (d % 7)); }).join(t("common.list_sep"));
    return t("rule.work_hours", { days: days, start: w.start, end: w.end });
  }

  function render() {
    view.innerHTML = '<div class="note">' + O.esc(t("rule.page_hint")) + " " + O.esc(workText(data.work)) + '</div><div class="card" id="rl_card"></div>';
    var card = document.getElementById("rl_card");
    card.appendChild(O.table({
      cols: [
        { t: t("rule.col.type"), cls: "wrap", f: function (r) { return "<b>" + O.esc(t("rule.code." + r.code)) + '</b><div class="muted" style="font-size:12px">' + O.esc(t("rule.desc." + r.code)) + "</div>"; } },
        { t: t("rule.col.triggers"), cls: "wrap", f: function (r) {
          return r.triggers.map(function (x) { return O.esc(say(r, x)); }).join("<br>") + (r.base === "overdue" ? '<div class="muted" style="font-size:12px">' + O.esc(t("rule.max_is", { n: r.max_repeat })) + "</div>" : "");
        } },
        { t: t("rule.col.who"), cls: "wrap", f: function (r) { return O.esc(t("rule.who." + r.who)) + (r.esc_target && r.escalate ? '<div class="muted" style="font-size:12px">' + O.esc(t("rule.esc_on." + r.esc_target)) + "</div>" : ""); } },
        { t: t("rule.col.level"), f: function (r) { return O.pill(t("notify.level." + r.level), LEVEL_PILL[r.level]); } },
        { t: t("rule.col.state"), f: function (r) { return r.enabled ? O.pill(t("rule.on"), "ok") : O.pill(t("rule.off")); } }
      ],
      rows: data.rules, empty: t("common.empty"),
      acts: function () { return [{ icon: "edit", tip: t("common.edit"), fn: edit }]; }
    }));
  }

  function load() {
    O.api("GET", "/api/v1/reminder-rules").then(function (r) { data = r; render(); }, O.failed);
  }

  function opts(list, sel, label) {
    return list.map(function (v) { return '<option value="' + O.esc(v) + '"' + (String(v) === String(sel) ? " selected" : "") + ">" + O.esc(label ? label(v) : v) + "</option>"; }).join("");
  }

  function timeOpts(sel) {
    var list = TIMES.slice();
    if (sel && list.indexOf(sel) < 0) { list.push(sel); list.sort(); }
    return opts(list, sel || "09:00");
  }

  function edit(rule) {
    var rows = rule.triggers.map(function (x) { return JSON.parse(JSON.stringify(x)); });
    var modes = data.modes[rule.base], units = data.units[rule.base] || [];
    var box = document.createElement("div");
    box.style.display = "grid";
    box.style.gap = "12px";
    box.innerHTML = '<div class="muted" style="font-size:12.5px">' + O.esc(t("rule.desc." + rule.code)) + " · " + O.esc(t("rule.who_is", { who: t("rule.who." + rule.who) })) + "</div>" +
      '<div class="isrow"><label class="opts"><input type="checkbox" id="re_on"' + (rule.enabled ? " checked" : "") + " style=\"width:auto\"> " + O.esc(t("rule.enabled")) + "</label>" +
      '<label>' + O.esc(t("rule.col.level")) + ' <select class="inp" id="re_level" style="width:auto">' + opts(data.levels, rule.level, function (v) { return t("notify.level." + v); }) + "</select></label>" +
      (rule.base === "overdue" ? '<label>' + O.esc(t("rule.max_repeat")) + ' <input class="inp" id="re_max" type="number" min="1" max="30" style="width:70px" value="' + rule.max_repeat + '"></label>' : "") + "</div>" +
      (rule.esc_target ? '<label class="opts"><input type="checkbox" id="re_esc" style="width:auto"' + (rule.escalate ? " checked" : "") + "> " + O.esc(t("rule.esc_on." + rule.esc_target)) + "</label>" : "") +
      '<div class="fld"><label>' + O.esc(t("rule.col.triggers")) + '</label><div class="rfrows" id="re_rows"></div><div style="margin-top:6px"><button type="button" class="btn sm" id="re_add">' +
        O.icon("plus", 14) + " " + O.esc(t("rule.add_trigger")) + '</button></div><span class="em" id="re_err" style="color:var(--bad);font-size:12px"></span></div>' +
      '<div class="rprev" id="re_prev"></div>';
    var rowsEl = box.querySelector("#re_rows");

    function rowHtml(x, i) {
      var h = '<div class="rfrow" data-i="' + i + '"><span class="muted">' + (i + 1) + ".</span>";
      if (modes.length > 1) h += '<select class="inp" data-k="mode">' + opts(modes, x.mode, function (v) { return t("rule.mode." + v); }) + "</select>";
      if (x.mode === "before" || x.mode === "evt") {
        h += '<input class="inp" data-k="amount" type="number" min="1" max="999" style="width:70px" value="' + (x.amount || 1) + '">';
        h += '<select class="inp" data-k="unit">' + opts(units, x.unit, function (v) { return t("rule.unit." + v); }) + "</select>";
        if (x.unit === "d" || x.unit === "wd") h += '<select class="inp" data-k="time_of_day">' + timeOpts(x.time_of_day) + "</select>";
      } else if (x.mode === "after") {
        h += '<select class="inp" data-k="repeat_mode">' + opts(["wday", "day", "once"], x.repeat_mode || "wday", function (v) { return t("rule.repeat." + v); }) + "</select>";
        if (x.repeat_mode === "once") h += '<input class="inp" data-k="amount" type="number" min="1" max="999" style="width:70px" value="' + (x.amount || 1) + '">';
        h += '<select class="inp" data-k="time_of_day">' + timeOpts(x.time_of_day) + "</select>";
      } else if (x.mode === "week") {
        h += '<select class="inp" data-k="weekday">' + opts([1, 2, 3, 4, 5, 6, 7], x.weekday || 5, function (v) { return t("common.wd" + (v % 7)); }) + "</select>";
        h += '<select class="inp" data-k="time_of_day">' + timeOpts(x.time_of_day) + "</select>";
      } else if (x.mode === "mday") {
        var days = [];
        for (var d = 1; d <= 28; d++) days.push(d);
        h += '<select class="inp" data-k="month_day">' + opts(days, x.month_day || 1, function (v) { return t("rule.day_n", { n: v }); }) + "</select>";
        h += '<select class="inp" data-k="time_of_day">' + timeOpts(x.time_of_day) + "</select>";
      } else if (x.mode !== "now") {
        h += '<select class="inp" data-k="time_of_day">' + timeOpts(x.time_of_day) + "</select>";
      }
      h += '<span class="rfsay">' + O.esc(say(rule, x)) + "</span>";
      h += '<button type="button" class="ib danger" data-del="' + i + '" title="' + O.esc(t("common.delete")) + '"' + (rows.length < 2 ? " disabled" : "") + ">" + O.icon("trash") + "</button></div>";
      return h;
    }
    /* keep the row consistent after a change (fields the mode does not use are cleared) */
    function normalize(x) {
      if (x.mode === "before" || x.mode === "evt") {
        if (units.indexOf(x.unit) < 0) x.unit = units[0];
        if (!x.amount) x.amount = 1;
        if (x.unit === "d" || x.unit === "wd") { if (!x.time_of_day) x.time_of_day = "09:00"; } else x.time_of_day = "";
        x.repeat_mode = ""; x.weekday = null; x.month_day = null;
      } else if (x.mode === "after") {
        if (!x.repeat_mode) x.repeat_mode = "wday";
        if (x.repeat_mode !== "once") x.amount = 0; else if (!x.amount) x.amount = 1;
        if (!x.time_of_day) x.time_of_day = "09:00";
        x.unit = ""; x.weekday = null; x.month_day = null;
      } else if (x.mode === "now") {
        x.amount = 0; x.unit = ""; x.time_of_day = ""; x.repeat_mode = ""; x.weekday = null; x.month_day = null;
      } else {
        if (!x.time_of_day) x.time_of_day = x.mode === "wday" ? "17:00" : "09:00";
        x.amount = 0; x.unit = ""; x.repeat_mode = "";
        x.weekday = x.mode === "week" ? (x.weekday || 5) : null;
        x.month_day = x.mode === "mday" ? (x.month_day || 1) : null;
      }
      return x;
    }
    function draw() {
      rowsEl.innerHTML = rows.map(rowHtml).join("");
      preview();
    }
    var preview = O.debounce(function () {
      var p = box.querySelector("#re_prev");
      box.querySelector("#re_err").textContent = "";
      O.api("POST", "/api/v1/reminder-rules/preview", payload()).then(function (res) {
        var at = res.base ? t("rule.prev.time", { date: res.base.slice(0, 10), wd: t("common.wd" + res.base_wd), time: res.base.slice(11) }) : "";
        var head = res.kind === "due" ? t("rule.prev.due", { at: at }) : res.kind === "overdue" ? t("rule.prev.overdue", { at: at })
          : res.kind === "event" ? t("rule.prev.event", { at: at }) : t("rule.prev.cycle");
        var fmt = function (x) { return t("rule.prev.time", { date: x.at.slice(0, 10), wd: t("common.wd" + x.wd), time: x.at.slice(11) }); };
        p.innerHTML = "<b>" + O.esc(t("rule.prev.title")) + '</b><div class="muted" style="font-size:12.5px;margin:2px 0 6px">' + O.esc(head) + "</div><ul>" + res.rows.map(function (r) {
          return "<li>" + O.esc(say(rule, rows[r.index])) + " → " + (r.times.length ? '<span class="mono">' + O.esc(r.times.map(fmt).join(t("common.list_sep"))) + "</span>" + (r.more ? " " + O.esc(t("rule.prev.more", { n: r.more })) : "")
            : '<span class="muted">' + O.esc(t("rule.prev.none")) + "</span>") + "</li>";
        }).join("") + '</ul><div class="muted" style="font-size:12px">' + O.esc(t("rule.prev.note")) + "</div>";
      }, function (e) {
        p.innerHTML = "";
        box.querySelector("#re_err").textContent = e && e.fields && e.fields.triggers ? e.fields.triggers : (e && e.message) || "";
      });
    }, 300);
    function payload() {
      var mx = box.querySelector("#re_max");
      var esc = box.querySelector("#re_esc");
      return { id: rule.id, triggers: rows, level: box.querySelector("#re_level").value, enabled: box.querySelector("#re_on").checked,
        max_repeat: mx ? +mx.value : rule.max_repeat, escalate: esc ? esc.checked : false };
    }
    rowsEl.addEventListener("change", function (e) {
      var row = e.target.closest(".rfrow");
      var k = e.target.getAttribute("data-k");
      if (!row || !k) return;
      var x = rows[+row.getAttribute("data-i")];
      var v = e.target.value;
      x[k] = k === "amount" || k === "weekday" || k === "month_day" ? +v : v;
      normalize(x);
      draw();
    });
    rowsEl.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button[data-del]") : null;
      if (!b || rows.length < 2) return;
      rows.splice(+b.getAttribute("data-del"), 1);
      draw();
    });
    box.querySelector("#re_add").addEventListener("click", function () {
      rows.push(normalize({ mode: modes[0], amount: 1, unit: units[0] || "", time_of_day: "", repeat_mode: "", weekday: null, month_day: null }));
      draw();
    });
    box.addEventListener("change", function (e) { if (e.target.id === "re_max") preview(); });
    draw();
    O.modal({ title: t("rule.edit_title", { name: t("rule.code." + rule.code) }), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      return O.api("PUT", "/api/v1/reminder-rules/" + rule.id, payload()).then(function () { O.status(t("common.saved")); load(); }, function (e) {
        if (e && e.fields) { box.querySelector("#re_err").textContent = e.fields.triggers || e.fields.max_repeat || e.message; O.status(e.message, "err"); return Promise.reject(null); }
        return Promise.reject(e);
      });
    } }] });
  }

  load();
})();
