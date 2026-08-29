
import streamlit as st
import jdatetime
import pandas as pd
from datetime import timedelta
import urllib.request
import json
import io

# ---------- Helper Functions ----------
def fetch_data(symbol):
    """Fetch summary-table data for a given symbol from TGJU API"""
    url = f'https://api.tgju.org/v1/market/indicator/summary-table-data/{symbol}?lang=fa&order_dir=asc&start=0&length=5000'
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0',
        'X-Requested-With': 'XMLHttpRequest',
        'Referer': f'https://www.tgju.org/profile/{symbol}/history'
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode())
    except Exception as e:
        st.error(f"خطا در دریافت داده {symbol}: {e}")
        return None

def parse_shamsi_date(date_str):
    """Convert Shamsi date string (YYYY/MM/DD) to jdatetime.date"""
    # jdatetime.date.fromisoformat expects YYYY-MM-DD, so replace / with -
    return jdatetime.date.fromisoformat(date_str.replace('/', '-'))

def generate_shamsi_dates(start_str, end_str):
    """Generate list of Shamsi date strings from start to end (inclusive)"""
    start = parse_shamsi_date(start_str)
    end = parse_shamsi_date(end_str)
    dates = []
    current = start
    while current <= end:
        dates.append(current.strftime('%Y/%m/%d'))
        current += timedelta(days=1)
    return dates

def build_dataframe(symbol, start_date_str, end_date_str, data):
    """Build DataFrame for a symbol over the given Shamsi date range, filling missing dates"""
    # Extract rows for this symbol
    rows = data.get('data', [])
    
    # Map date string to row dict for quick lookup
    date_to_row = {}
    for r in rows:
        date_str = r[7]  # Shamsi date column YYYY/MM/DD
        
        # Ensure prices are converted to Toman
        low_rial = float(r[1].replace(',', ''))
        high_rial = float(r[2].replace(',', ''))
        close_rial = float(r[3].replace(',', ''))

        low_toman = low_rial / 10
        high_toman = high_rial / 10
        close_toman = close_rial / 10
        
        daily_trading_average = (low_toman + high_toman + close_toman) / 3
        
        date_to_row[date_str] = {
            'low': low_toman,
            'high': high_toman,
            'avg_trading': daily_trading_average
        }
    
    # Generate all dates in the required range
    all_dates_in_range = generate_shamsi_dates(start_date_str, end_date_str)
    
    result_rows = []
    last_valid_data = None
    
    for current_date_str in all_dates_in_range:
        if current_date_str in date_to_row:
            current_day_data = date_to_row[current_date_str]
            result_rows.append({
                'تاریخ شمسی': current_date_str,
                'کمترین': current_day_data['low'],
                'بیشترین': current_day_data['high'],
                'میانگین': current_day_data['avg_trading']
            })
            last_valid_data = current_day_data
        elif last_valid_data:
            # Use last valid data if current day's data is missing
            result_rows.append({
                'تاریخ شمسی': current_date_str,
                'کمترین': last_valid_data['low'],
                'بیشترین': last_valid_data['high'],
                'میانگین': last_valid_data['avg_trading']
            })
        else:
            # If no data found yet (e.g., before first available data point)
            result_rows.append({
                'تاریخ شمسی': current_date_str,
                'کمترین': None,
                'بیشترین': None,
                'میانگین': None
            })
    
    return pd.DataFrame(result_rows)


# ---------- Streamlit App ----------
st.set_page_config(page_title="گزارش قیمت طلا و ارز", layout="wide")

st.title("📈 گزارش روزانه قیمت طلا و ارز")
st.markdown("""
این برنامه به شما اجازه می‌دهد تا گزارش روزانه قیمت‌های طلا و ارز را در بازه تاریخی شمسی دلخواه دریافت کنید.
""")

# Define symbols and their Persian names
symbol_map = {
    'geram18': 'طلای ۱۸ عیار',
    'sekee': 'سکه تمام بهار آزادی',
    'nim': 'نیم سکه',
    'rob': 'ربع سکه',
    'mesghal': 'مظنه طلا',
    'price_dollar_rl': 'دلار آزاد'
}

with st.sidebar:
    st.header("تنظیمات گزارش")
    selected_symbols = st.multiselect(
        "نمادهای مورد نظر را انتخاب کنید:",
        options=list(symbol_map.keys()),
        default=['geram18', 'sekee', 'price_dollar_rl'],
        format_func=lambda x: symbol_map[x]
    )

    st.subheader("بازه تاریخی شمسی")
    today_shamsi = jdatetime.date.today().strftime('%Y/%m/%d')
    default_start_date = (jdatetime.date.today() - timedelta(days=30)).strftime('%Y/%m/%d')

    start_date_input = st.text_input(
        "تاریخ شروع (مثال: 1405/01/01)",
        value=default_start_date
    )
    end_date_input = st.text_input(
        "تاریخ پایان (مثال: 1405/05/31)",
        value=today_shamsi
    )

    # Date validation
    valid_dates = True
    try:
        start_jdate = parse_shamsi_date(start_date_input)
        end_jdate = parse_shamsi_date(end_date_input)
        if start_jdate > end_jdate:
            st.error("تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.")
            valid_dates = False
    except ValueError:
        st.error("فرمت تاریخ نامعتبر است. لطفاً از فرمت YYYY/MM/DD استفاده کنید.")
        valid_dates = False

if st.button("ایجاد گزارش اکسل"):
    if valid_dates and selected_symbols:
        with st.spinner("در حال دریافت و پردازش داده‌ها..."):
            try:
                output = io.BytesIO()
                with pd.ExcelWriter(output, engine='openpyxl') as writer:
                    for symbol in selected_symbols:
                        st.info(f"در حال دریافت داده برای {symbol_map[symbol]}...")
                        raw_data = fetch_data(symbol)
                        if raw_data:
                            df = build_dataframe(symbol, start_date_input, end_date_input, raw_data)
                            # Rename columns for clarity in Excel tabs
                            df = df.rename(columns={
                                'کمترین': 'کمترین قیمت روزانه',
                                'بیشترین': 'بیشترین قیمت روزانه',
                                'میانگین': 'میانگین قیمت معاملاتی'
                            })
                            df.to_excel(writer, sheet_name=symbol_map[symbol], index=False)
                        else:
                            st.warning(f"داده‌ای برای {symbol_map[symbol]} یافت نشد.")
                
                output.seek(0)
                st.download_button(
                    label="دانلود فایل اکسل گزارش",
                    data=output.getvalue(),
                    file_name=f"gold_price_report_{start_date_input.replace('/','_')}_to_{end_date_input.replace('/','_')}.xlsx",
                    mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                )
                st.success("گزارش اکسل شما با موفقیت آماده شد!")
                
            except Exception as e:
                st.error(f"خطایی رخ داد: {e}")
    elif not selected_symbols:
        st.warning("لطفاً حداقل یک نماد را برای گزارش انتخاب کنید.")
