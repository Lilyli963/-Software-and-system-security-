<?php
	$con=mysqli_connect("localhost","root","");
	mysqli_select_db($con,"test");
	$sql="select * from table where id=1";
	$result=mysqli_query($con,$sql);
	while($row=mysql_fetch_array($result)){
		echo $row['name'];
	}
?>