<div class="export-box">
<h3>仕分帳データのエクスポート</h3>
<p>各種会計データインポート用のCSVファイルをダウンロードする事ができます。<br>
<a href="<?php echo home_url(); ?>/#search-box">※エクスポートしたい期間など必要に応じて上部検索ボックスで指定してください。</a></p>
<h4>MFクラウド会計</h4>
<p>仕分帳用に出力する最初の取引Noを入力した上でCSVエクスポートボタンを押してください。</p>
<p>MFクラウド会計でのインポートは「<a href="https://accounting.moneyforward.com/books" target="_blank">会計帳簿 > 仕訳帳</a>」より行います。</p>
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

<h4>freee</h4>
<p>freeeでのインポートは「<a href="https://secure.freee.co.jp/hub_pages/deals" target="_blank">取引 > 取引のインポート</a>」より行います。</p>

<div class="row">
<div class="col-sm-9 col-sm-offset-3">
<button type="submit" name="action" value="csv_freee" class="search-submit btn btn-block btn-primary">freee用CSVエクスポート　<span class="glyphicon glyphicon-download-alt"></span></button>
</div>
</div>
</div><!-- /.export-box -->
