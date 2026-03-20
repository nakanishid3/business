/**
 * 在庫管理システム - Google Apps Script
 * 対象メール: aeb00403@nifty.com / 件名: 売上速報バリューとれとれ市場
 * 処理: 毎日20時以降の最終メールを基に在庫を自動減算
 */

// ===== 設定 =====
const CONFIG = {
  SPREADSHEET_ID: 'YOUR_SPREADSHEET_ID',  // ← 作成したスプレッドシートのIDに変更
  SENDER_EMAIL:   'aeb00403@nifty.com',
  EMAIL_SUBJECT:  '売上速報バリューとれとれ市場',
  TIMEZONE:       'Asia/Tokyo',
  // シート名
  SHEET_INVENTORY: '商品在庫',
  SHEET_HISTORY:   '取引履歴',
  SHEET_REPORT:    '売上レポート',
};

// ===== メイン処理（トリガーから呼び出し） =====

/**
 * メールを確認して在庫を更新する（毎日20時に自動実行）
 */
function checkAndUpdateInventory() {
  const today = new Date();
  const todayStr = Utilities.formatDate(today, CONFIG.TIMEZONE, 'yyyy/MM/dd');

  // 当日のメールを検索
  const query = `from:(${CONFIG.SENDER_EMAIL}) subject:(${CONFIG.EMAIL_SUBJECT}) after:${todayStr}`;
  const threads = GmailApp.search(query);

  if (threads.length === 0) {
    Logger.log('該当メールなし: ' + todayStr);
    return;
  }

  // 20時以降の最終メールを取得
  let lastMessage = null;
  let lastTime    = null;

  for (const thread of threads) {
    const messages = thread.getMessages();
    for (const msg of messages) {
      const msgDate = msg.getDate();
      const hour = parseInt(Utilities.formatDate(msgDate, CONFIG.TIMEZONE, 'HH'), 10);
      if (hour >= 20) {
        if (!lastTime || msgDate > lastTime) {
          lastTime    = msgDate;
          lastMessage = msg;
        }
      }
    }
  }

  if (!lastMessage) {
    Logger.log('20時以降のメールなし: ' + todayStr);
    return;
  }

  // メール本文を解析
  const body   = lastMessage.getPlainBody();
  const parsed = parseEmailBody(body);

  if (!parsed || parsed.items.length === 0) {
    Logger.log('メール解析失敗 または 商品情報なし');
    return;
  }

  Logger.log('解析結果: ' + JSON.stringify(parsed));

  // スプレッドシートを更新（当日の手動取り込み分があれば上書き）
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  updateInventoryAndHistory(ss, parsed, lastTime, true);
  updateSalesReport(ss);
}

// ===== メール解析 =====

/**
 * メール本文を解析して日付・時刻・店舗・商品リストを返す
 */
function parseEmailBody(body) {
  const lines = body.split(/\r?\n/).map(l => l.trim());

  // 日付
  const dateMatch = body.match(/■\s*(\d{4}年\d{2}月\d{2}日)\s*■/);
  const dateStr   = dateMatch ? dateMatch[1] : '';

  // 時刻
  const timeMatch = body.match(/■\s*(\d{2}時\d{2}分)\s*現在\s*■/);
  const timeStr   = timeMatch ? timeMatch[1] : '';

  // 店舗名
  const storeMatch = body.match(/【(.+?)】/);
  const storeName  = storeMatch ? storeMatch[1] : '';

  // 商品情報（品名行 → 数量・金額行 のペアを抽出）
  const items        = [];
  let   inItemSection = false;
  let   pendingItem   = null;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];

    // ヘッダー行で商品セクション開始
    if (/品名\s+点数\s+金額/.test(line)) {
      inItemSection = true;
      continue;
    }

    if (!inItemSection) continue;

    // 区切り線・合計行でセクション終了
    if (/^={10,}/.test(line) || /^合計/.test(line)) {
      if (pendingItem) {
        items.push(pendingItem);
        pendingItem = null;
      }
      if (/^合計/.test(line)) break;
      continue;
    }

    // 区切り破線はスキップ
    if (/^-{5,}/.test(line)) continue;

    // 数量・金額行（例: "         5     1,000"）
    const numMatch = line.match(/^(\d+)\s+([\d,]+)$/);
    if (numMatch && pendingItem) {
      pendingItem.quantity = parseInt(numMatch[1], 10);
      pendingItem.amount   = parseInt(numMatch[2].replace(/,/g, ''), 10);
      items.push(pendingItem);
      pendingItem = null;
      continue;
    }

    // 品名行（空白・区切り・数字のみ行はスキップ）
    if (line && !/^\d/.test(line) && !/^[=\-]/.test(line)) {
      if (pendingItem) items.push(pendingItem); // 前の品名が未確定なら保存
      pendingItem = { name: line, quantity: 0, amount: 0 };
    }
  }

  return { date: dateStr, time: timeStr, store: storeName, items };
}

// ===== スプレッドシート更新 =====

/**
 * 在庫マスタ減算 & 取引履歴追記
 * @param {boolean} overwriteToday - true の場合、当日分の既存履歴を在庫を戻した上で上書き
 */
function updateInventoryAndHistory(ss, data, recordDate, overwriteToday) {
  const inventorySheet = ss.getSheetByName(CONFIG.SHEET_INVENTORY);
  const historySheet   = ss.getSheetByName(CONFIG.SHEET_HISTORY);
  const recordDateStr  = Utilities.formatDate(recordDate, CONFIG.TIMEZONE, 'yyyy/MM/dd HH:mm');
  const todayStr       = Utilities.formatDate(recordDate, CONFIG.TIMEZONE, 'yyyy/MM/dd');

  if (overwriteToday) {
    // 当日分の既存履歴を取得し、在庫を戻してから行を削除（下から処理して行ずれ防止）
    const lastRow = historySheet.getLastRow();
    if (lastRow > 1) {
      const histData = historySheet.getRange(2, 1, lastRow - 1, 4).getValues();
      for (let i = histData.length - 1; i >= 0; i--) {
        if (String(histData[i][3]).startsWith(todayStr)) {
          updateMasterInventory(inventorySheet, String(histData[i][0]), Number(histData[i][1]));
          historySheet.deleteRow(i + 2); // +2：ヘッダー行(1) + 0-indexed補正
        }
      }
    }
  }

  for (const item of data.items) {
    // 在庫マスタを減算
    updateMasterInventory(inventorySheet, item.name, -item.quantity);

    // 取引履歴に追記
    historySheet.appendRow([
      item.name,       // 品名
      item.quantity,   // 点数
      item.amount,     // 金額
      recordDateStr,   // 情報取得日時
      data.store,      // 店舗名
      data.date,       // メール日付
      data.time,       // メール時刻
    ]);
  }

  Logger.log('在庫・履歴更新完了: ' + recordDateStr + ' / ' + data.items.length + '件');
}

/**
 * 在庫マスタの指定商品の数量を増減する
 * 商品が存在しない場合は新規追加
 */
function updateMasterInventory(sheet, itemName, quantityChange) {
  const values = sheet.getRange(2, 1, Math.max(sheet.getLastRow() - 1, 1), 3).getValues();

  for (let i = 0; i < values.length; i++) {
    if (values[i][0] === itemName) {
      const currentQty = values[i][1] || 0;
      sheet.getRange(i + 2, 2).setValue(currentQty + quantityChange);
      sheet.getRange(i + 2, 3).setValue(new Date());
      return;
    }
  }

  // 新規追加
  const newRow = sheet.getLastRow() + 1;
  sheet.getRange(newRow, 1, 1, 3).setValues([[itemName, quantityChange, new Date()]]);
}

// ===== 当日データ取り込み（手動） =====

/**
 * 実行時点の直近メールを基に在庫を更新する（手動実行・時刻制限なし）
 */
function checkAndUpdateInventoryManual() {
  const ui = SpreadsheetApp.getUi();
  const today = new Date();
  const todayStr = Utilities.formatDate(today, CONFIG.TIMEZONE, 'yyyy/MM/dd');

  // 当日のメールを検索
  const query = `from:(${CONFIG.SENDER_EMAIL}) subject:(${CONFIG.EMAIL_SUBJECT}) after:${todayStr}`;
  const threads = GmailApp.search(query);

  if (threads.length === 0) {
    ui.alert('本日の該当メールなし: ' + todayStr);
    return;
  }

  // 現時点までの直近メールを取得（時刻制限なし）
  let lastMessage = null;
  let lastTime    = null;

  for (const thread of threads) {
    const messages = thread.getMessages();
    for (const msg of messages) {
      const msgDate = msg.getDate();
      if (!lastTime || msgDate > lastTime) {
        lastTime    = msgDate;
        lastMessage = msg;
      }
    }
  }

  if (!lastMessage) {
    ui.alert('本日のメールなし: ' + todayStr);
    return;
  }

  // メール本文を解析
  const body   = lastMessage.getPlainBody();
  const parsed = parseEmailBody(body);

  if (!parsed || parsed.items.length === 0) {
    ui.alert('メール解析失敗 または 商品情報なし');
    return;
  }

  // スプレッドシートを更新
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  updateInventoryAndHistory(ss, parsed, lastTime);
  updateSalesReport(ss);

  ui.alert('当日データ取り込み完了: ' + todayStr);
}

// ===== 前日データ取り込み（手動） =====

/**
 * 前日20時以降の最終メールを基に在庫を更新する（手動実行）
 */
function checkAndUpdateInventoryYesterday() {
  const ui = SpreadsheetApp.getUi();
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);

  const yesterdayStr = Utilities.formatDate(yesterday, CONFIG.TIMEZONE, 'yyyy/MM/dd');
  const todayStr     = Utilities.formatDate(today,     CONFIG.TIMEZONE, 'yyyy/MM/dd');

  // 前日のメールを検索
  const query = `from:(${CONFIG.SENDER_EMAIL}) subject:(${CONFIG.EMAIL_SUBJECT}) after:${yesterdayStr} before:${todayStr}`;
  const threads = GmailApp.search(query);

  if (threads.length === 0) {
    ui.alert('該当メールなし: ' + yesterdayStr);
    return;
  }

  // 20時以降の最終メールを取得
  let lastMessage = null;
  let lastTime    = null;

  for (const thread of threads) {
    const messages = thread.getMessages();
    for (const msg of messages) {
      const msgDate = msg.getDate();
      const hour = parseInt(Utilities.formatDate(msgDate, CONFIG.TIMEZONE, 'HH'), 10);
      if (hour >= 20) {
        if (!lastTime || msgDate > lastTime) {
          lastTime    = msgDate;
          lastMessage = msg;
        }
      }
    }
  }

  if (!lastMessage) {
    ui.alert('前日20時以降のメールなし: ' + yesterdayStr);
    return;
  }

  // メール本文を解析
  const body   = lastMessage.getPlainBody();
  const parsed = parseEmailBody(body);

  if (!parsed || parsed.items.length === 0) {
    ui.alert('メール解析失敗 または 商品情報なし');
    return;
  }

  // スプレッドシートを更新
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  updateInventoryAndHistory(ss, parsed, lastTime);
  updateSalesReport(ss);

  ui.alert('前日データ取り込み完了: ' + yesterdayStr);
}

// ===== 売上レポート更新 =====

/**
 * 取引履歴を集計して月別・週別の販売数と売上金額を売上レポートシートに書き出す
 */
function updateSalesReport(ss) {
  if (!ss) ss = SpreadsheetApp.getActiveSpreadsheet();

  const historySheet = ss.getSheetByName(CONFIG.SHEET_HISTORY);
  const reportSheet  = ss.getSheetByName(CONFIG.SHEET_REPORT);

  const histData = historySheet.getDataRange().getValues();
  if (histData.length <= 1) return;

  const monthly = {};
  const weekly  = {};

  for (let i = 1; i < histData.length; i++) {
    const [itemName, quantity, amount, dateStr, store] = histData[i];
    if (!itemName || !dateStr || store === '手動登録') continue;

    const date = new Date(dateStr);
    if (isNaN(date)) continue;

    const monthKey = Utilities.formatDate(date, CONFIG.TIMEZONE, 'yyyy年MM月');

    // 週の月曜日を週キーに使用
    const dow       = date.getDay(); // 0=日, 1=月
    const weekStart = new Date(date);
    weekStart.setDate(date.getDate() - ((dow + 6) % 7));
    const weekKey = Utilities.formatDate(weekStart, CONFIG.TIMEZONE, 'MM/dd') + '週';

    const qty = Number(quantity) || 0;
    const amt = Number(amount)   || 0;

    if (!monthly[monthKey])            monthly[monthKey]            = {};
    if (!monthly[monthKey][itemName])  monthly[monthKey][itemName]  = { qty: 0, amt: 0 };
    monthly[monthKey][itemName].qty += qty;
    monthly[monthKey][itemName].amt += amt;

    if (!weekly[weekKey])             weekly[weekKey]             = {};
    if (!weekly[weekKey][itemName])   weekly[weekKey][itemName]   = { qty: 0, amt: 0 };
    weekly[weekKey][itemName].qty += qty;
    weekly[weekKey][itemName].amt += amt;
  }

  // 月別書き出し
  const monthRows = [];
  for (const month of Object.keys(monthly).sort()) {
    for (const item of Object.keys(monthly[month]).sort()) {
      monthRows.push([month, item, monthly[month][item].qty, monthly[month][item].amt]);
    }
  }

  // 週別書き出し
  const weekRows = [];
  for (const week of Object.keys(weekly).sort()) {
    for (const item of Object.keys(weekly[week]).sort()) {
      weekRows.push([week, item, weekly[week][item].qty, weekly[week][item].amt]);
    }
  }

  // 書き込み（既存データクリア → 再書き込み）
  const lastRow = Math.max(reportSheet.getLastRow(), 3);
  reportSheet.getRange(3, 1, lastRow, 4).clearContent();
  reportSheet.getRange(3, 6, lastRow, 4).clearContent();

  if (monthRows.length > 0) {
    reportSheet.getRange(3, 1, monthRows.length, 4).setValues(monthRows);
  }
  if (weekRows.length > 0) {
    reportSheet.getRange(3, 6, weekRows.length, 4).setValues(weekRows);
  }

  Logger.log('売上レポート更新完了');
}

// ===== 初期セットアップ =====

/**
 * スプレッドシートの全シートを初期化する（初回のみ実行）
 */
function setupSpreadsheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const ui = SpreadsheetApp.getUi();

  const res = ui.alert('セットアップ', '全シートを初期化します。既存データは失われます。続けますか？', ui.ButtonSet.YES_NO);
  if (res !== ui.Button.YES) return;

  setupInventorySheet(ss);
  setupHistorySheet(ss);
  setupReportSheet(ss);

  ui.alert('セットアップ完了しました。\nトリガーを設定するには「在庫管理 > トリガー設定」を実行してください。');
}

function setupInventorySheet(ss) {
  let sheet = ss.getSheetByName(CONFIG.SHEET_INVENTORY);
  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.SHEET_INVENTORY);
  } else {
    sheet.clear();
  }

  // ===== 在庫マスタ（A〜C列）=====
  sheet.getRange('A1:C1').setValues([['品名', '在庫数量', '最終更新日時']]);
  sheet.getRange('A1:C1')
    .setBackground('#4472C4')
    .setFontColor('#FFFFFF')
    .setFontWeight('bold')
    .setHorizontalAlignment('center');

  // 列幅
  sheet.setColumnWidth(1, 160);
  sheet.setColumnWidth(2, 80);
  sheet.setColumnWidth(3, 150);
}

function setupHistorySheet(ss) {
  let sheet = ss.getSheetByName(CONFIG.SHEET_HISTORY);
  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.SHEET_HISTORY);
  } else {
    sheet.clear();
  }

  const headers = ['品名', '点数', '金額', '情報取得日時', '店舗名', 'メール日付', 'メール時刻'];
  sheet.getRange(1, 1, 1, headers.length).setValues([headers])
    .setBackground('#4472C4').setFontColor('#FFFFFF').setFontWeight('bold')
    .setHorizontalAlignment('center');

  sheet.setColumnWidth(1, 160);
  sheet.setColumnWidth(2, 60);
  sheet.setColumnWidth(3, 80);
  sheet.setColumnWidth(4, 150);
  sheet.setColumnWidth(5, 100);
  sheet.setColumnWidth(6, 120);
  sheet.setColumnWidth(7, 100);
}

function setupReportSheet(ss) {
  let sheet = ss.getSheetByName(CONFIG.SHEET_REPORT);
  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.SHEET_REPORT);
  } else {
    sheet.clear();
  }

  // 月別
  sheet.getRange('A1').setValue('【月別集計】')
    .setBackground('#4472C4').setFontColor('#FFFFFF').setFontWeight('bold');
  sheet.getRange('A2:D2').setValues([['年月', '品名', '販売数', '売上金額']])
    .setBackground('#8EA9C1').setFontWeight('bold').setHorizontalAlignment('center');

  // 週別
  sheet.getRange('F1').setValue('【週別集計】')
    .setBackground('#4472C4').setFontColor('#FFFFFF').setFontWeight('bold');
  sheet.getRange('F2:I2').setValues([['週', '品名', '販売数', '売上金額']])
    .setBackground('#8EA9C1').setFontWeight('bold').setHorizontalAlignment('center');

  sheet.setColumnWidth(1, 100);
  sheet.setColumnWidth(2, 160);
  sheet.setColumnWidth(3, 70);
  sheet.setColumnWidth(4, 90);
  sheet.setColumnWidth(6, 100);
  sheet.setColumnWidth(7, 160);
  sheet.setColumnWidth(8, 70);
  sheet.setColumnWidth(9, 90);
}

// ===== トリガー設定 =====

/**
 * 毎日20時半に checkAndUpdateInventory を実行するトリガーを設定
 */
function createDailyTrigger() {
  // 既存の同名トリガーを削除
  ScriptApp.getProjectTriggers()
    .filter(t => t.getHandlerFunction() === 'checkAndUpdateInventory')
    .forEach(t => ScriptApp.deleteTrigger(t));

  ScriptApp.newTrigger('checkAndUpdateInventory')
    .timeBased()
    .everyDays(1)
    .atHour(20)
    .nearMinute(30)
    .inTimezone(CONFIG.TIMEZONE)
    .create();

  SpreadsheetApp.getUi().alert('トリガー設定完了：毎日20時半に自動実行されます');
}

// ===== メニュー追加 =====

/**
 * スプレッドシートを開いたときにカスタムメニューを追加
 */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('在庫管理')
    .addItem('初期セットアップ',               'setupSpreadsheet')
    .addSeparator()
    .addItem('売上レポート更新',               'updateSalesReport')
    .addSeparator()
    .addItem('当日データ取り込み（手動）',     'checkAndUpdateInventoryManual')
    .addItem('前日データ取り込み（手動）',     'checkAndUpdateInventoryYesterday')
    .addItem('トリガー設定（毎日20時半）',     'createDailyTrigger')
    .addToUi();
}
