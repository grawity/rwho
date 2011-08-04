#!/usr/bin/env perl
# rwho data collector daemon

use warnings;
use strict;
use constant PATH_UTMP => '/var/run/utmp';

use Data::Dumper;
use Getopt::Long qw(:config no_ignore_case bundling);
use JSON;
use LWP::UserAgent;
use Linux::Inotify2;
use POSIX qw(:errno_h :signal_h);
use Pod::Usage;
use Sys::Hostname;

my $notify_url = "http://equal.cluenet.org/rwho/server.php";
my $update_interval = 10*60;
my $verbose = 0;
my $do_fork = 0;
my $do_single = 0;
my $hide_root = 1;
my $pidfile;
my $pidfile_h;

my $my_hostname;
my $my_fqdn;
my $poller_pid;

sub canon_hostname {
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
sub ut_dump {
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

# read uptime data
sub get_uptime {
	my $loadavg;
	if (open(my $fh, "<", "/proc/loadavg")) {
		chomp($loadavg = <$fh>);
		close($fh);
	}
	return $loadavg;
}

# "utmp changed" handler
sub update {
	my @utmp = ut_dump();
	for (@utmp) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	if ($hide_root) {
		@utmp = grep {$_->{user} ne "root"} @utmp;
	}
	debug("update: uploading ".scalar(@utmp)." items");
	upload("put", \@utmp);
}

# Upload data to server
sub upload {
	my ($action, $utmp) = @_;
	my %data = (
		host => $my_hostname,
		fqdn => $my_fqdn,
		action => $action,
		utmp => encode_json($utmp),
		uptime => get_uptime(),
	);
	my $ua = LWP::UserAgent->new;
	my $resp = $ua->post($notify_url, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
	debug("upload: ".$resp->status_line);
}

sub watch_inotify {
	$0 = "rwhod: inotify(".PATH_UTMP.")";
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub { update(); });
	debug("watch: idling");
	while (1) {
		$inotify->poll;
	}
}

sub watch_poll {
	$0 = "rwhod: poll(${update_interval}s)";
	debug("poll: updating every $update_interval seconds");
	while (1) {
		sleep $update_interval;
		update();
	}
}

sub debug {
	local $" = " ";
	$verbose and print "rwhod[$$]: @_\n";
}

sub daemonize {
	chdir "/"
		or die "can't chdir to /: $!";
	open STDIN, "<", "/dev/null"
		or die "can't read /dev/null: $!";
	open STDOUT, ">", "/dev/null"
		or die "can't write /dev/null: $!";

	my $pid = fork;
	if (!defined $pid) {
		die "can't fork: $!";
	} elsif ($pid) {
		debug("forked to $pid");
		exit;
	} else {
		if (POSIX::setsid() < 0) {
			warn "setsid failed: $!";
		}
		debug("running in background");
	}
}

sub reap {
	my $pid = wait;
	$SIG{CHLD} = \&reap;
	debug("received SIGCHLD for $pid");
}

sub cleanup {
	if (defined $poller_pid) {
		debug("cleanup: killing poller");
		$SIG{CHLD} = \&reap;
		kill SIGTERM, $poller_pid;
	}
	debug("cleanup: removing all records");
	upload("destroy", []);
	exit;
}

sub fork_poller {
	debug("starting poller");
	$SIG{CHLD} = \&reap_poller;
	return forked {
		$SIG{INT} = "DEFAULT";
		$SIG{TERM} = "DEFAULT";
		$SIG{CHLD} = \&reap;
		watch_poll();
	};
}

sub reap_poller {
	my $pid = wait;
	$SIG{CHLD} = \&reap_poller;

	if (defined $poller_pid and $pid == $poller_pid) {
		debug("poller exited, restarting");
		$poller_pid = fork_poller();
	} else {
		debug("received SIGCHLD for unknown pid $pid");
	}
}

## startup code
GetOptions(
	"d|daemon"	=> \$do_fork,
	"help"		=> sub { pod2usage(1); },
	"i|interval=i"	=> \$update_interval,
	"man"		=> sub { pod2usage(-exitstatus => 0,
				-verbose => 2); },
	"pidfile=s"	=> \$pidfile,
	"root"		=> sub { $hide_root = 0; },
	"server=s"	=> \$notify_url,
	"single"	=> \$do_single,
	"v|verbose"	=> \$verbose,
) or pod2usage(1);

if (!defined $notify_url) {
	die "error: notify_url not specified\n";
}

$0 = "rwhod";

$my_hostname = hostname;
$my_fqdn = canon_hostname($my_hostname);
$my_hostname =~ s/\..*$//;
debug("identifying as \"$my_fqdn\" ($my_hostname)");

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

if ($do_single) {
	if ($do_fork) {
		warn "warning: --fork ignored in single mode\n";
	}
	debug("doing single update");
	$0 = "rwhod: updating";
	update();
	exit();
}

if (defined $pidfile) {
	open $pidfile_h, ">", $pidfile
		or die "unable to open pidfile '$pidfile'\n";
}

# chdir after opening pidfile
chdir "/";

debug("doing initial update");
update();

if ($do_fork) {
	daemonize();
}

if (defined $pidfile_h) {
	print $pidfile_h "$$\n";
	close $pidfile_h;
}

if ($update_interval) {
	$poller_pid = fork_poller();
}
debug("starting inotify watch");
watch_inotify();

__END__

=head1 NAME

rwhod - remote-who collector daemon

=head1 SYNOPSIS

rwhod [options]

=head1 OPTIONS

=over 8

=item B<-d>, B<--daemon>

Fork to background after initial update.

=item B<--help>

Obvious.

=item B<-i I<seconds>>, B<--interval=I<seconds>>

Periodic update every I<seconds> seconds (600 by default). Zero to disable.

=item B<--man>

Display the manual page.

=item B<--pidfile I<path>>

Write PID to file.

=item B<--root>

Include root logins.

=item B<--server I<url>>

Use specified server URL.

=item B<--single>

Do a single update and exit.

=item B<-v>, B<--verbose>

Print informative messages.

=back

=head1 DEPENDENCIES

Perl 5.10, apparently.

B<C<getaddrinfo>>: C<Socket::GetAddrInfo>, C<Net::addrinfo>, or the C<getent> binary.

B<C<utmp> access>: C<Sys::Utmp> or C<User::Utmp>

B<C<inotify>>: C<Linux::Inotify2> for real-time C<utmp> monitoring.

B<HTTP>: C<JSON> and C<LWP::UserAgent>

=head1 BUGS

It's useless.

C<inotify> requirement makes the script unportable outside Linux.

Using C<getaddrinfo> just to find our own FQDN might be overkill when the rest of the world can use C<gethostbyaddr> on 127.0.0.1.

Data submission URL is hardcoded.

Hosts are only identified by their FQDN, so it's possible to upload fake data.

Incremental updates are not yet implemented (server-side support exists).

=cut
