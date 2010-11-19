<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
	<xsl:template match="/">
		<html>
		<head>
			<title>Users logged in</title>
			<meta name="robots" content="noindex, nofollow"/>
			<link rel="stylesheet" href="rwho.css"/>
		</head>
		<body>
			<table id="sessions">
			<thead>
				<th style="min-width: 15ex">user</th>
				<th style="min-width: 10ex">host</th>
				<th style="min-width: 8ex">line</th>
				<th style="min-width: 40ex">address</th>
			</thead>
			<xsl:apply-templates/>
			</table>
		</body>
		</html>
	</xsl:template>

	<xsl:template match="/rwho/row">
		<tr>
			<td>
				<xsl:value-of select="user"/>
			</td>
			<td>
				<xsl:choose>
					<xsl:when test="/rwho/@summary">
						<xsl:value-of select="substring-before(host, '.')"/>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="host"/>
					</xsl:otherwise>
				</xsl:choose>
			</td>
			<td>
				<xsl:choose>
					<xsl:when test="@summary">
						(<xsl:value-of select="line"/> ttys)
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="line"/>
					</xsl:otherwise>
				</xsl:choose>
			</td>
			<td>
				<xsl:choose>
					<xsl:when test="string-length(rhost)">
						<xsl:value-of select="rhost"/>
					</xsl:when>
					<xsl:otherwise>
						<i>(none)</i>
					</xsl:otherwise>
				</xsl:choose>
			</td>
		</tr>
	</xsl:template>
</xsl:stylesheet>
