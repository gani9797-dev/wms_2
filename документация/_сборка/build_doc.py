# -*- coding: utf-8 -*-
"""Сборка итоговой функциональной документации TL WMS (.docx) на базе существующего документа."""
import sys, os, copy, shutil, zipfile, re
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.shared import Cm, Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

import content as C

SRC = sys.argv[1]
DST = sys.argv[2]
VERSION = sys.argv[3] if len(sys.argv) > 3 else '21.10.2026'
V_DAY, V_MON, V_YEAR = VERSION.split('.')

doc = Document(SRC)
body = doc.element.body


# ---------------------------------------------------------------- helpers
def children():
    return [c for c in body.iterchildren()]


def text_of(el):
    return ''.join(t.text or '' for t in el.iter(qn('w:t')))


def find_par(prefix, start=0, exact=False):
    """Найти первый w:p, текст которого начинается с prefix."""
    for i, el in enumerate(children()):
        if i < start:
            continue
        if el.tag != qn('w:p'):
            continue
        t = text_of(el).strip()
        if (t == prefix) if exact else t.startswith(prefix):
            return i, el
    raise LookupError(f'не найден абзац: {prefix!r}')


def style_of(el):
    ppr = el.find(qn('w:pPr'))
    if ppr is None:
        return ''
    ps = ppr.find(qn('w:pStyle'))
    return ps.get(qn('w:val')) if ps is not None else ''


def set_style(el, style_name):
    ppr = el.find(qn('w:pPr'))
    if ppr is None:
        ppr = OxmlElement('w:pPr')
        el.insert(0, ppr)
    ps = ppr.find(qn('w:pStyle'))
    if ps is None:
        ps = OxmlElement('w:pStyle')
        ppr.insert(0, ps)
    ps.set(qn('w:val'), doc.styles[style_name].style_id)


def new_par(text, style=None):
    p = doc.add_paragraph(text, style=style) if style else doc.add_paragraph(text)
    el = p._p
    body.remove(el)
    return el


def new_note(text):
    p = doc.add_paragraph()
    run = p.add_run(text)
    run.italic = True
    el = p._p
    body.remove(el)
    return el


def new_table(header, rows, widths=None):
    t = doc.add_table(rows=1 + len(rows), cols=len(header), style='Table Grid')
    t.autofit = True
    hdr = t.rows[0]
    for i, h in enumerate(header):
        cell = hdr.cells[i]
        cell.text = ''
        par = cell.paragraphs[0]
        run = par.add_run(h)
        run.bold = True
        for r in rows:
            pass
    for ri, row in enumerate(rows, start=1):
        for ci, val in enumerate(row):
            cell = t.rows[ri].cells[ci]
            cell.text = ''
            par = cell.paragraphs[0]
            par.add_run(str(val))
    if widths:
        for ci, w in enumerate(widths):
            for row in t.rows:
                row.cells[ci].width = Cm(w)
        grid = t._tbl.find(qn('w:tblGrid'))
        if grid is not None:
            for col, w in zip(grid.findall(qn('w:gridCol')), widths):
                col.set(qn('w:w'), str(int(w * 567)))
    el = t._tbl
    body.remove(el)
    return el


def build_blocks(blocks):
    out = []
    for b in blocks:
        kind = b[0]
        if kind == 'h2':
            out.append(new_par(b[1], 'Heading 2'))
        elif kind == 'h3':
            out.append(new_par(b[1], 'Heading 3'))
        elif kind == 'p':
            out.append(new_par(b[1]))
        elif kind == 'b':
            out.append(new_par(b[1], 'List Bullet'))
        elif kind == 'b2':
            out.append(new_par(b[1], 'List Bullet 2'))
        elif kind == 'note':
            out.append(new_note(b[1]))
        elif kind == 't':
            header, rows = b[1], b[2]
            widths = b[3] if len(b) > 3 else None
            out.append(new_table(header, rows, widths))
        else:
            raise ValueError(kind)
    return out


def insert_before(anchor, blocks):
    els = build_blocks(blocks)
    for el in els:
        anchor.addprevious(el)
    return els


def insert_after(anchor, blocks):
    els = build_blocks(blocks)
    cur = anchor
    for el in els:
        cur.addnext(el)
        cur = el
    return els


def remove(el):
    el.getparent().remove(el)


def replace_with_blocks(el, blocks):
    els = build_blocks(blocks)
    for x in els:
        el.addprevious(x)
    remove(el)


def table_of(el):
    """Если el — таблица, вернуть число строк."""
    return len(el.findall(qn('w:tr')))


def header_row(el):
    tr = el.find(qn('w:tr'))
    if tr is None:
        return []
    return [text_of(tc).strip() for tc in tr.findall(qn('w:tc'))]


log = []

# ---------------------------------------------------------------- 1. подзаголовки 1.1 / 1.2 / 2.1 / 2.2 -> Heading 2
for prefix in ('1.1. Справочник ТС', '1.2. Карта ворот', '2.1. Прием поступлений WEB', '2.2. Прием поступления ТСД'):
    i, el = find_par(prefix)
    set_style(el, 'Heading 2')
    log.append(f'стиль Heading 2: {text_of(el).strip()[:40]}')

# ---------------------------------------------------------------- 2. раздел 0. Дашборд
i, h1 = find_par('1. Реестр прибытия транспортных средств')
insert_before(h1, C.DAШBOARD)
log.append('добавлен раздел «0. Дашборд»')

# ---------------------------------------------------------------- 3. раздел 2.2 — приёмка на ТСД
i, h22 = find_par('2.2. Прием поступления ТСД')
j, ph = find_par('Описать логику работы', start=i, exact=True)
replace_with_blocks(ph, C.RECEIPT_TSD)
log.append('раздел 2.2 — заполнена логика приёмки на ТСД')

# ---------------------------------------------------------------- 4. раздел 3 — отгрузки
i, h3 = find_par('3. Отгрузки')
i2, h31 = find_par('3.1. Заказы')
between = children()[i + 1:i2]
# удаляем дублирующий блок из модуля «Поступления» (после таблицы полей отгрузки)
fields_idx = None
for k, el in enumerate(between):
    if el.tag == qn('w:tbl') and table_of(el) == 31:
        fields_idx = k
if fields_idx is None:
    raise RuntimeError('не найдена таблица полей отгрузки')
dup = between[fields_idx + 1:]
for el in dup:
    remove(el)
log.append(f'удалён дублирующийся блок раздела «Поступления» из раздела 3 ({len(dup)} элементов)')
# «Описать логику» внутри раздела 3
k, ph = find_par('Описать логику', start=i, exact=True)
remove(ph)
# вставляем содержательную часть отгрузок после таблицы полей
fields_el = children()[i + 1:][0]
anchor = None
for el in children():
    if el.tag == qn('w:tbl') and table_of(el) == 31:
        anchor = el
        break
insert_after(anchor, C.SHIPMENTS)
log.append('раздел 3 — добавлены статусная модель, мастер формирования, выпуск заданий, ПКМ, печать')

# ---------------------------------------------------------------- 5. раздел 3.1 + 3.2 + 3.3
i, h4 = find_par('4. Внутрискладские операции')
insert_before(h4, C.ORDERS + C.PACKING + C.CHECKING)
log.append('добавлены разделы: 3.1 (детализация), 3.2 Упаковка заказов, 3.3 Проверка заказов')

# ---------------------------------------------------------------- 6. раздел 4 — внутрискладские операции
i, h5 = find_par('5. Ячейки хранения')
insert_before(h5, C.INTERNAL_OPS)
log.append('раздел 4 — добавлена таблица фактических операций')

# ---------------------------------------------------------------- 7. раздел 5.1 — карта 3D
i, h51 = find_par('5.1. Карта склада 3D')
j, ph = find_par('Описать логику', start=i, exact=True)
replace_with_blocks(ph, C.MAP_3D)
log.append('раздел 5.1 — заполнена логика карты склада 3D')

# ---------------------------------------------------------------- 8. раздел 8.1 — товарный запас
i, h9 = find_par('9. Контрагенты и грузополучатели')
insert_before(h9, C.STOCK)
log.append('добавлен раздел «8.1. Товарный запас»')

# ---------------------------------------------------------------- 9. раздел 10.2 — просмотр сделки
i, h102 = find_par('10.2. Просмотр сделки')
j, ph = find_par('???', start=i, exact=True)
replace_with_blocks(ph, C.DEALS)
log.append('раздел 10.2 — заполнено содержание модуля')

# ---------------------------------------------------------------- 10. раздел 11 — отчёты
i, h11 = find_par('11. Отчеты')
j, ph = find_par('???', start=i, exact=True)
replace_with_blocks(ph, C.REPORTS)
log.append('раздел 11 — заполнен перечень отчётов и выгрузок')

# ---------------------------------------------------------------- 11. разделы 13.2–13.4 и 14–19
i, h20 = find_par('20. Настройки «Склад и хранение»')
insert_before(h20, C.AUTO_BILLS + C.PRINT_LINE + C.KPI_SETTINGS +
              C.TSD + C.INTEGRATIONS + C.USERS_RIGHTS + C.AUTOMATION + C.NUMBERING + C.AUDIT)
log.append('добавлены разделы 13.2, 13.3, 13.4 и 14–19')

# ---------------------------------------------------------------- 12. ответы по открытым вопросам в конце
tail_blocks = [
    ("p", "Ответы по открытым вопросам, зафиксированным при подготовке документа:"),
    ("t", ["Вопрос", "Решение"], [
        ["Разукомплектация возвратных заказов",
         "Выполняется через действие «Создать на основании» в поступлении: доприёмка недостачи (A-105107-Н) и доприёмка излишков (A-105107-И). В текущей версии действие сформировано как заготовка — требуется доработка создания документа."],
        ["Настройка букв для нумерации документов",
         "Реализовано в разделе 18 «Нумерация документов и печатные формы»: П — поступление, В — возврат, П-Н — расхождение по недостаче, О — отгрузка, EXT — внешний заказ."],
        ["Настройка временной шкалы слота (30 мин, 1 час и т.п.)",
         "Задаётся в настройках «Ворота и бронирование»: для каждого ворота указываются интервал слотов и единица измерения (минута или час). В карте ворот слоты отображаются по этим интервалам."],
        ["НДС %",
         "Ставка НДС задаётся в документе и в позиции; рассчитываются суммы без НДС, НДС и итог. Значение по умолчанию — в настройках документов и нумерации."],
        ["Настройка флагов (марки, серийники, срок годности)",
         "Признаки задаются в карточке товара: партия, срок годности, серийный номер, маркировка «Честный знак», группа подконтрольной продукции, максимальный процент истечения. Проверка кода маркировки выполняется при приёмке и сортировке."],
        ["Серийники — формат и ограничения",
         "Формат и ограничения серийных номеров задаются настройками модуля номенклатуры; при приёмке и сортировке выполняется сверка с реестром, повторный серийник отклоняется."],
    ], [6.0, 12.0]),
    ("p", "Дополнительные рабочие штрихкоды, используемые в модулях приёмки и отгрузки:"),
    ("t", ["п/п", "Наименование", "Номер", "Кому нужен"], [
        ["1", "ШК Конец работы / закрытие LPN", "100-1000", "Контролёр погрузочно-разгрузочных работ, приёмщик"],
        ["2", "ШК расхождения (закрытие с недобором)", "100-1001", "Приёмщик, когда накладную нельзя принять полностью"],
        ["3", "ШК документа поступления", "10005343П / 10005343В", "Приёмщик на ТСД для выбора поставки"],
        ["4", "ШК LPN (паллета приёмки)", "MEZ000125", "Приёмщик: буквы — категория товара, цифры — порядковый номер печати LPN"],
        ["5", "ШК кода отгрузки", "S-205107", "Кладовщик на ТСД при сортировке по отгрузке"],
        ["6", "ШК SSCC паллеты", "SSCC паллеты", "Кладовщик: состав паллеты, упаковочный и паллетный листы"],
        ["7", "ШК товара (EAN-13)", "4600000000000", "Кладовщик и водитель: отбор, приёмка, размещение"],
        ["8", "ШК марки «Честный знак»", "код марки", "Приёмщик и кладовщик: сверка с реестром марок"],
        ["9", "ШК ячейки", "A3-100-06-1", "Кладовщик: адресное хранение, маршрут отбора"],
        ["10", "Личный ШК сотрудника", "персональный ШК", "Проходная и учёт выхода на смену"],
    ], [1.2, 5.6, 4.2, 7.0]),
]
# вставка в конец документа (перед sectPr)
sect = body.find(qn('w:sectPr'))
for el in build_blocks(tail_blocks):
    if sect is not None:
        sect.addprevious(el)
    else:
        body.append(el)
log.append('добавлены ответы по открытым вопросам и расширенная таблица штрихкодов')

# ---------------------------------------------------------------- 13. обновление оглавления
toc_sdt = None
for child in body.iterchildren():
    if child.tag != qn('w:sdt'):
        continue
    content = child.find(qn('w:sdtContent'))
    if content is None:
        continue
    instr = ''.join(t.text or '' for t in content.iter(qn('w:instrText')))
    if 'TOC' in instr:
        toc_sdt = child
        break
if toc_sdt is not None:
    content = toc_sdt.find(qn('w:sdtContent'))
    for child in list(content):
        content.remove(child)
    title_p = doc.add_paragraph('Оглавление', style='TOC Heading')
    body.remove(title_p._p)
    content.append(title_p._p)
    fld_p = doc.add_paragraph()
    body.remove(fld_p._p)
    r1 = fld_p.add_run()
    fc = OxmlElement('w:fldChar')
    fc.set(qn('w:fldCharType'), 'begin')
    fc.set(qn('w:dirty'), 'true')
    r1._r.append(fc)
    r2 = fld_p.add_run()
    it = OxmlElement('w:instrText')
    it.set(qn('xml:space'), 'preserve')
    it.text = ' TOC \\o "1-3" \\h \\z \\u '
    r2._r.append(it)
    r3 = fld_p.add_run()
    fs = OxmlElement('w:fldChar')
    fs.set(qn('w:fldCharType'), 'separate')
    r3._r.append(fs)
    r4 = fld_p.add_run('Оглавление обновляется автоматически при открытии документа (Word: F9 — обновить поле).')
    r5 = fld_p.add_run()
    fe = OxmlElement('w:fldChar')
    fe.set(qn('w:fldCharType'), 'end')
    r5._r.append(fe)
    content.append(fld_p._p)
    log.append('оглавление переведено на автообновляемое поле')

# ---------------------------------------------------------------- 14. версия в настройках документа
settings = doc.settings.element
uf = settings.find(qn('w:updateFields'))
if uf is None:
    uf = OxmlElement('w:updateFields')
    settings.append(uf)
uf.set(qn('w:val'), 'true')
log.append('включено обновление полей при открытии')

# ---------------------------------------------------------------- 15. версия на обложке и в колонтитуле
for t in doc.element.body.iter(qn('w:t')):
    if t.text == 'Версия: 0':
        t.text = 'Версия: 2'
for t in doc.element.body.iter(qn('w:t')):
    if t.text == '8' and t.getparent().getprevious() is not None:
        pass
# второй элемент '8' сразу после 'Версия: 2'
ts = list(doc.element.body.iter(qn('w:t')))
for i, t in enumerate(ts):
    if (t.text or '') == 'Версия: 2' and i + 1 < len(ts) and (ts[i + 1].text or '') == '8':
        ts[i + 1].text = V_DAY
for sec in doc.sections:
    for p in sec.footer.paragraphs:
        for r in p.runs:
            if '06.10.2026' in r.text:
                r.text = r.text.replace('06.10.2026', VERSION)
            if '08.10.2026' in r.text:
                r.text = r.text.replace('08.10.2026', VERSION)
log.append(f'версия документа приведена к {VERSION}')

# ---------------------------------------------------------------- 16. свойства файла
cp = doc.core_properties
cp.title = 'TL WMS'
cp.subject = 'Функциональная документация модулей, форм и взаимодействия'
cp.author = f'Версия: {VERSION}'
cp.comments = f'Версия от {VERSION}'
cp.category = 'Функциональная документация'

# ---------------------------------------------------------------- 17. сохранение
os.makedirs(os.path.dirname(DST), exist_ok=True)
doc.save(DST)

# автор на обложке (привязан к свойствам титульного листа)
tmp = DST + '.tmp'
with zipfile.ZipFile(DST) as zin, zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as zout:
    for item in zin.infolist():
        data = zin.read(item.filename)
        if item.filename == 'customXml/item1.xml':
            data = data.replace('<CompanyEmail>Автор: </CompanyEmail>'.encode('utf-8'),
                                '<CompanyEmail>Автор: Даниил Тимощенко</CompanyEmail>'.encode('utf-8'))
        zout.writestr(item, data)
os.replace(tmp, DST)
log.append(f'сохранено: {DST}')

print('\n'.join('* ' + x for x in log))
