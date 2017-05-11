<div class="export-box">
<h3>仕分帳データのエクスポート</h3>
<p>各種会計データインポート用のCSVファイルをダウンロードする事ができます。
※必要に応じて上部検索ボックスで期間などを指定してください。</p>
<h4>MFクラウド会計</h4>
<p>仕分帳用に出力する最初の取引Noを入力した上でCSVエクスポートボタンを押してください。</p>
<div class="row">
<div class="col-sm-3">
<dl>
<dt><label for="number_start">開始取引No</label></dt>
<dd><input type="text" value="" name="number_start" class="form-control" /></dd>
</dl>
</div>
<div class="col-sm-9">
<button type="submit" name="action" value="csv_mf" class="search-submit btn btn-block btn-primary">MFクラウド会計用CSVエクスポート　<span class="glyphicon glyphicon-download-alt"></span></button>
</div>
</div>
<p>MFクラウド会計でのインポートは「<a href="https://biz.moneyforward.com/books/" target="_blank">会計帳簿 > 仕訳帳</a>」より行います。</p>

<h4>freee</h4>
<div class="row">
<div class="col-sm-3">
</div>
<div class="col-sm-9">
<button type="submit" name="action" value="csv_freee" class="search-submit btn btn-block btn-primary">freee用CSVエクスポート　<span class="glyphicon glyphicon-download-alt"></span></button>
</div>
</div>
</div><!-- /.export-box -->