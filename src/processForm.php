<!DOCTYPE html>
<html lang="en"></html>
<head>
    <title>Upload and analyse image file</title>
    <meta charset="UTF-8">
    <link rel="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css">
    
</head>
<body>
<div class="row justify-content-center">
<h1>Upload image here</h1>
<form method="post" enctype="multipart/form-data" action="uploadHandler.php">
    <div class="mb-3">
        <label for="image" class="form-label">Upload an Image file</label>
        <input class="form-control" type="file" id="image" name="image" accept="image/*" required>
    </div>
    <button type="submit" name="submit">Upload image</button>    
</form>
</div>
</body>
</html> 