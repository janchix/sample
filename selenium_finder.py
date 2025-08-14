from selenium import webdriver
from selenium_authenticated_proxy import SeleniumAuthenticatedProxy
from datetime import datetime
import time
import json
import random
import argparse
import os
import sys
sys.stdout.reconfigure(encoding='utf-8')

# Set up argument parser
parser = argparse.ArgumentParser(description="Process some arguments.")

# Add arguments
parser.add_argument('--keyword', type=str, help='Keyword')
parser.add_argument('--url', type=str, help='Search url')
parser.add_argument('--selenium_url', type=str, help='Selenium server url')
parser.add_argument('--oxy_user', type=str, help='Oxylabs user')
parser.add_argument('--oxy_pass', type=str, help='Oxylabs pass')
parser.add_argument('--oxy_url', type=str, help='Oxylabs url')
parser.add_argument('--req_id', type=str, help='Request ID')

# Parse arguments
args = parser.parse_args()
selenium_url= args.selenium_url
oxy_user=args.oxy_user
oxy_pass=args.oxy_pass
oxy_url =args.oxy_url
req_id=args.req_id

user_agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.5481.177 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.93 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.96 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
]
# Randomly select a user agent
random_user_agent = random.choice(user_agents)

# Initialize Chrome options
chrome_options = webdriver.ChromeOptions()
chrome_options.add_argument("--headless=new")  # Headless mode for modern Chrome versions
chrome_options.add_argument("--no-sandbox")
chrome_options.add_argument("--disable-dev-shm-usage")
chrome_options.add_argument("--disable-blink-features=AutomationControlled")  # Avoid detection
chrome_options.add_argument("--user-agent={random_user_agent}")

chrome_options.add_argument("--ignore-certificate-errors")  # Ignore SSL errors
#chrome_options.add_argument("--incognito")  # Optional: use incognito mode

# Remote Selenium Server details
REMOTE_SELENIUM_URL = f"{selenium_url}"  # Replace with the actual URL of your Selenium server
print(REMOTE_SELENIUM_URL)
# Proxy credentials
PROXY = f'http://{oxy_user}:{oxy_pass}@{oxy_url}'


# Initialize SeleniumAuthenticatedProxy
proxy_helper = SeleniumAuthenticatedProxy(proxy_url=PROXY)

# Enrich Chrome options with proxy authentication
proxy_helper.enrich_chrome_options(chrome_options)

# Start WebDriver with enriched options
driver = webdriver.Remote(
    command_executor=REMOTE_SELENIUM_URL,  # Remote server URL
    options=chrome_options,
)

# Your automation or scraping code here

result = ''
keyword =args.keyword
url = args.url
print(keyword)
print(url)
results = {}
results['keyword'] = keyword
results['url'] = url
results['error'] = ''
results['html'] = ''

# Get the current date and time
current_datetime = datetime.now()
# Convert to a string
datetime_folder = current_datetime.strftime('%Y-%m-%d')
if not os.path.exists(datetime_folder):
    os.mkdir(datetime_folder)
    print(f"Directory '{datetime_folder}' created.")

datetime_string = current_datetime.strftime('%Y-%m-%d %H:%M:%S')

result_file =  f'{datetime_folder}/{req_id}===={keyword}===={datetime_string}.json'
try:
    # Navigate to a website to verify proxy is working
    driver.get(url)  # Verifies IP via the proxy
    time.sleep(3)  # Wait for the page to load

    # Print the page source to confirm proxy IP
    results['html'] = driver.page_source
    
    print(driver.page_source)
       
    # Retrieve and print browser logs for debugging
    browser_logs = driver.get_log("browser")
    print("Browser Logs:")
    for log in browser_logs:
        print(log)

    # Retrieve and print performance logs for debugging network activity
    # performance_logs = driver.get_log("performance")
    # print("Performance Logs:")
    # for log in performance_logs:
    #     print(log)

    # Screenshot for additional debugging
    # driver.save_screenshot("screenshot.png")
    # print("Screenshot saved to screenshot.png")

except Exception as e:
    print("Error: {e}")
    results['error'] = f"Error: {e}"

finally:
    # Quit the driver
    driver.quit()
with open(result_file, "w", encoding="utf-8") as f:
    json.dump(results, f)