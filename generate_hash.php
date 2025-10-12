<?php
// generate_hash.php
// Simple web form para mag-generate ng password hash gamit ang password_hash()

$generated = '';
$input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = isset($_POST['password']) ? trim($_POST['password']) : '';
    if ($input === '') {
        $generated = 'Error: Walang password na naipasok.';
    } else {
        // PASSWORD_DEFAULT uses bcrypt (or better) on current PHP version
        $hash = password_hash($input, PASSWORD_DEFAULT);
        $generated = $hash ? $hash : 'Error: Hindi nagawa ang hash.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Generate Password Hash</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;padding:30px}
  .card{background:#fff;padding:20px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,0.06);max-width:640px;margin:auto}
  input[type="password"]{width:100%;padding:10px;margin:8px 0;border:1px solid #ddd;border-radius:6px}
  button{padding:10px 16px;border-radius:6px;border:0;background:#2563eb;color:#fff;cursor:pointer}
  pre{background:#111827;color:#e6f0ff;padding:12px;border-radius:6px;overflow:auto}
  .note{font-size:13px;color:#555;margin-top:8px}
</style>
</head>
<body>
<div class="card">
  <h2>Generate Password Hash (bcrypt)</h2>
  <form method="post" autocomplete="off">
    <label>Enter password:</label>
    <input type="password" name="password" value="<?php echo htmlspecialchars($input); ?>" required>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="submit">Generate Hash</button>
      <button type="button" onclick="document.querySelector('input[name=password]').value='';">Clear</button>
    </div>
  </form>

  <?php if ($generated): ?>
    <h3>Hash result</h3>
    <?php if (strpos($generated, 'Error:') === 0): ?>
      <p style="color:#b91c1c;"><?php echo htmlspecialchars($generated); ?></p>
    <?php else: ?>
      <pre><?php echo htmlspecialchars($generated); ?></pre>
      <p class="note">I-save itong hash sa database sa `password` column. Gumamit ng <code>password_verify($plain, $hash)</code> kapag magkoconfirm ng login.</p>
    <?php endif; ?>
  <?php endif; ?>

  <hr>
  <h4>Verify example (server-side)</h4>
  <pre>
// Example PHP:
// $plain = 'userInputPassword';
// $hashFromDB = 'the-hash-you-saved';
// if (password_verify($plain, $hashFromDB)) {
//     // success
// } else {
//     // wrong password
// }
  </pre>
</div>
</body>
</html>
