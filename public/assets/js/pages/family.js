/* Family records (highly sensitive; every view is audited) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { kw: "", page: 1 };

  view.innerHTML = '<div class="note warn">' + O.esc(t("family.page_note")) + '</div><div class="bar"><input type="search" id="fm_kw" placeholder="' + O.esc(t("family.search")) + '"><span class="sp"></span></div><div class="card" id="fm_card"></div>';

  function load() {
    O.api("GET", "/api/v1/family", null, { kw: state.kw, page: state.page }).then(function (res) {
      var card = document.getElementById("fm_card");
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("family.col.person"), f: function (r) { return '<button type="button" class="lk" data-p="' + r.person_id + '">' + O.esc(r.person) + '</button><div class="muted mono" style="font-size:12px">' + O.esc(r.staff_no) + "</div>"; } },
          { t: t("staff.field.dept"), f: function (r) { return O.esc(r.dept || "—"); } },
          { t: t("family.field.name"), f: function (r) { return O.esc(r.name); } },
          { t: t("family.field.relation"), f: function (r) { return O.esc(O.person.dictText("relation", r.relation)); } },
          { t: t("family.field.gender"), f: function (r) { return O.esc(r.gender ? O.person.dictText("gender", r.gender) : "—"); } },
          { t: t("family.field.birth_date"), f: function (r) { return '<span class="mono">' + O.esc(r.birth_date || "—") + "</span>"; } },
          { t: t("family.field.employer"), f: function (r) { return O.esc(r.employer || "—"); } },
          { t: t("family.field.phone"), f: function (r) { return '<span class="mono">' + O.esc(r.phone || "—") + "</span>"; } },
          { t: t("family.field.address"), cls: "wrap", f: function (r) { return O.esc(r.address || "—"); } }
        ],
        rows: res.rows, empty: t("family.empty")
      }));
      card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
      if (!res.full) card.insertAdjacentHTML("beforeend", '<div class="iblegend">' + O.esc(t("family.masked_note")) + "</div>");
      card.onclick = function (e) {
        var b = e.target.closest ? e.target.closest("[data-p]") : null;
        if (b) O.person.open(+b.getAttribute("data-p"), { tab: "family", onSaved: load });
      };
    }, O.failed);
  }

  document.getElementById("fm_kw").addEventListener("input", O.debounce(function (e) { state.kw = e.target.value.trim(); state.page = 1; load(); }, 400));
  O.person.meta().then(load, O.failed);
})();
