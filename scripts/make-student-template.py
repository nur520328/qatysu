"""Оқушылар тізімінің үлгі файлын жасайды: frontend/public/templates/oqushylar-ulgi.xlsx.
Үлгі сервердің оқушы импортымен үйлесуі тиіс (StudentImportTest тексереді). Іске қосу: python3 scripts/make-student-template.py"""
from pathlib import Path
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

ROWS = 1200
wb = Workbook()
ws = wb.active
ws.title = 'Оқушылар'
font = Font(name='Arial', size=11)
bold = Font(name='Arial', size=11, bold=True)
note_fill = PatternFill('solid', fgColor='FFF7D6')
head_fill = PatternFill('solid', fgColor='DCE6F5')
thin = Side(style='thin', color='B8C2D0')
border = Border(left=thin, right=thin, top=thin, bottom=thin)

notes = [
    'Оқушылар тізімінің үлгісі («Сабаққа қатысу» платформасына жүктеу үшін)',
    '1. Әр оқушыға бір жол. 8-жолдан бастап толтырыңыз. Тақырып жолын (7-жол) өзгертпеңіз.',
    '2. Тегі, Аты және Сынып міндетті. Әкесінің аты болмаса, бос қалдырыңыз.',
    '3. Сынып әр жолда жазылады: сан мен бас әріп, арасында бос орынсыз, мысалы 7Ә немесе 11А. Әріпті қазақша пернетақтамен теріңіз (латынша A, B жарамайды). Сынып сайтта алдын ала ашылған болуы керек.',
    '4. Толтыру мысалы: Тегі «Үлгіев», Аты «Үлгі», Әкесінің аты «Үлгіұлы», Сынып «7Ә». Бос жолдарды өшірудің қажеті жоқ.',
]
for i, text in enumerate(notes, start=1):
    ws.merge_cells(start_row=i, start_column=1, end_row=i, end_column=5)
    c = ws.cell(row=i, column=1, value=text)
    c.font = Font(name='Arial', size=13, bold=True) if i == 1 else font
    c.alignment = Alignment(wrap_text=True, vertical='center')
    c.fill = note_fill
    ws.row_dimensions[i].height = 24 if i == 1 else (48 if i == 4 else 32)

HEADER = 7
for col, title in enumerate(['№', 'Тегі', 'Аты', 'Әкесінің аты', 'Сынып'], start=1):
    c = ws.cell(row=HEADER, column=col, value=title)
    c.font = bold; c.fill = head_fill; c.border = border
    c.alignment = Alignment(horizontal='center', vertical='center')
ws.row_dimensions[HEADER].height = 22

for n in range(1, ROWS + 1):
    r = HEADER + n
    ws.cell(row=r, column=1, value=n).alignment = Alignment(horizontal='center')
    for col in range(1, 6):
        c = ws.cell(row=r, column=col)
        c.font = font; c.border = border
        if col > 1:
            c.number_format = '@'  # мәтін: «7Ә», «11А» өзгеріссіз сақталады

for col, width in zip(range(1, 6), [7, 26, 22, 26, 11]):
    ws.column_dimensions[get_column_letter(col)].width = width
ws.freeze_panes = ws.cell(row=HEADER + 1, column=1)
ws.print_title_rows = f'{HEADER}:{HEADER}'
ws.page_setup.orientation = 'portrait'
ws.page_setup.fitToWidth = 1
ws.page_setup.fitToHeight = 0
ws.sheet_properties.pageSetUpPr.fitToPage = True
wb.save(Path(__file__).resolve().parent.parent / 'frontend/public/templates/oqushylar-ulgi.xlsx')
