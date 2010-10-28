#!/usr/bin/perl
# rwho data collector daemon

use warnings;
use strict;
use constant PATH_UTMP => '/var/run/utmp';

use POSIX qw(signal_h);
use Linux::Inotify2;
use Sys::Hostname;
use JSON;
use LWP::UserAgent;
use Data::Dumper;

my $DEBUG = 1;

my $notify_url = "http://equal.cluenet.org/~grawity/rwho/server.php";
my $update_interval = 10*60;

my $my_hostname;
my $my_fqdn;
my $pid_periodic;

sub canon_hostname($) {
	my $host = shift;
	if (eval {require Socket::GetAddrInfo}) {
		debug("canon_hostname: using Socket::GetAddrInfo");
		my %hint = (flags => Socket::GetAddrInfo->AI_CANONNAME);
		my ($err, @ai) = Socket::GetAddrInfo::getaddrinfo($host, "", \%hint);
		# FIXME: print error messages when needed
		return $err ? $host : ((shift @ai)->{canonname} // $host);
	}
	elsif (eval {require Net::addrinfo}) {
		debug("canon_hostname: using Net::addrinfo");
		my $hint = Net::addrinfo->new(
			flags => Net::addrinfo->AI_CANONNAME);
		my $ai = Net::addrinfo::getaddrinfo($host, undef, $hint);
		return (ref $ai eq "Net::addrinfo") ? $ai->canonname : $host;
	}
	else {
		debug("canon_hostname: using \"getent hosts\"");
		open my $fd, "-|", "getent", "hosts", $host;
		my @ai = split(" ", <$fd>);
		close $fd;
		return $ai[1] // $host;
	}
}

# Run code in subprocess
# $pid = forked { ... };
sub forked(&) {
	my $sub = shift;
	my $pid = fork();
	if ($pid) {return $pid} else {exit &$sub}
}

# Read the utmp file
sub ut_dump() {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		debug("ut_dump: using User::Utmp");
		while (my $ent = User::Utmp::getutxent()) {
			if ($ent->{ut_type} == User::Utmp->USER_PROCESS) {
				push @utmp, {
					user => $ent->{ut_user},
					line => $ent->{ut_line},
					host => $ent->{ut_host},
					time => $ent->{ut_time},
				};
			}
		}
		User::Utmp::endutxent();
	}
	elsif (eval {require Sys::Utmp}) {
		debug("ut_dump: using Sys::Utmp");
		my $utmp = Sys::Utmp->new();
		while (my $ent = $utmp->getutent()) {
			if ($ent->user_process) {
				push @utmp, {
					user => $ent->ut_user,
					line => $ent->ut_line,
					host => $ent->ut_host,
					time => $ent->ut_time,
				};
			}
		}
		$utmp->endutent();
	}
	else {
		die "error: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

# "utmp changed" handler
sub update() {
	my @data = ut_dump();
	for (@data) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	debug("update: uploading ".scalar(@data)." items");
	upload("put", \@data);
}

# Upload data to server
sub upload($$) {
	my ($action, $data) = @_;
	my $ua = LWP::UserAgent->new;
	my %data = (
		host => $my_hostname,
		fqdn => $my_fqdn,
		action => $action,
		utmp => encode_json($data),
	);
	my $resp = $ua->post($notify_url, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
	debug("upload: ".$resp->status_line);
}

sub watch() {
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub {update});
	debug("watch: idling");
	1 while $inotify->poll;
}

sub cleanup {
	if (defined $pid_periodic) {
		debug("cleanup: killing poller");
		kill SIGTERM, $pid_periodic;
	}
	debug("cleanup: removing all records");
	upload("destroy", []);
}

sub debug {
	local $" = " ";
	$DEBUG and print "rwhod[$$]: @_\n";
}

## startup code
if (!defined $notify_url) {
	die "error: notify_url not specified\n";
}

$my_hostname = hostname;
$my_hostname =~ s/\..*$//;
$my_fqdn = canon_hostname($my_hostname);
debug("identifying as \"$my_fqdn\" ($my_hostname)");

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

chdir "/";
debug("doing initial update");
update();
if ($update_interval) {
	debug("starting poller");
	$pid_periodic = forked {
		$0 = "rwhod: periodic(${update_interval}s)";
		$SIG{INT} = "DEFAULT";
		$SIG{TERM} = "DEFAULT";
		while (1) {
			debug("poller: sleeping $update_interval seconds");
			sleep $update_interval;
			update();
		}
	};
}
debug("starting inotify watch");
$0 = "rwhod: inotify(".PATH_UTMP.")";
watch();
